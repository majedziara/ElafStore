<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\PaymentAttempt;
use App\Models\PaymentGateway;
use App\Notifications\StoreMail;
use App\Services\InventoryService;
use App\Services\OrderAccessService;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

final class PaymentService
{
    public function __construct(private PayPalClient $client, private InventoryService $stock) {}

    public function start(Order $order): PaymentAttempt
    {
        return Cache::lock('order-payment:'.$order->id, 120)->block(5, function () use ($order) {
            $created = false;
            $attempt = DB::transaction(function () use ($order, &$created) {
                $o = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                abort_if($o->paid_at || $o->canceled_at, 409, 'Order is not payable.');
                $existing = $o->payments()->latest('id')->first();
                if ($existing) {
                    return $existing;
                }
                abort_if($o->reservation_expires_at->isPast() || $o->stock_status !== 'reserved', 409, 'Order reservation expired. Create a new bag.');
                $gateway = PaymentGateway::where('slug', 'paypal')->firstOrFail();
                abort_unless($gateway->enabled, 503, 'PayPal is disabled.');
                $created = true;

                return PaymentAttempt::create(['uuid' => (string) Str::uuid(), 'order_id' => $o->id, 'gateway' => 'paypal', 'environment' => $gateway->environment,
                    'gateway_snapshot' => $this->client->configuration($gateway->environment)]);
            }, 3);
            if (! $created) {
                if ($attempt->status === 'creating') {
                    $attempt->update(['status' => 'unknown', 'last_error' => 'Interrupted create request needs reconciliation.']);
                }

                return $attempt;
            }
            // A persisted attempt is never blindly re-created after an ambiguous network failure.
            try {
                $payload = $this->client->create($attempt->gateway_snapshot, $order, $attempt->uuid);
                $id = $payload['id'] ?? null;
                abort_unless(is_string($id) && $id !== '', 502, 'PayPal order ID missing.');
                $attempt->update(['provider_order_id' => $id]);
                $link = collect($payload['links'] ?? [])->first(fn ($l) => in_array($l['rel'] ?? null, ['approve', 'payer-action'], true));
                $url = $link['href'] ?? null;
                abort_unless($url && parse_url($url, PHP_URL_SCHEME) === 'https' &&
                    in_array(parse_url($url, PHP_URL_HOST), ['www.paypal.com', 'www.sandbox.paypal.com'], true), 502, 'PayPal approval URL missing.');
                $attempt->update(['provider_order_id' => $id, 'approval_url' => $url, 'status' => 'awaiting_approval']);
            } catch (Throwable $e) {
                $attempt->update(['status' => 'unknown', 'last_error' => 'Provider request needs reconciliation.']);
                report($e);
            }

            return $attempt->fresh();
        });
    }

    public function sync(PaymentAttempt $attempt, bool $captureApproved = true): PaymentAttempt
    {
        return Cache::lock('order-payment:'.$attempt->order_id, 120)->block(5, function () use ($attempt, $captureApproved) {
            $a = $attempt->fresh();
            if (! $a->provider_order_id) {
                return $a;
            }
            $config = $a->gateway_snapshot;
            $payload = $this->client->inspect($config, $a->provider_order_id);
            $this->validateOrder($a, $payload);
            $knownCaptures = data_get($payload, 'purchase_units.0.payments.captures', []);
            $order = $a->order->fresh();
            if (! $order->paid_at && ! $knownCaptures && ! $a->capture_requested_at && $order->reservation_expires_at->isPast() &&
                in_array($payload['status'] ?? null, ['CREATED', 'SAVED', 'PAYER_ACTION_REQUIRED', 'APPROVED'], true)) {
                DB::transaction(function () use ($a, $order) {
                    $o = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                    if (! $o->paid_at) {
                        $this->stock->release($o);
                        $o->update(['payment_status' => 'expired', 'fulfillment_status' => 'canceled', 'canceled_at' => now()]);
                        if ($a->status !== 'expired') {
                            OrderEvent::create(['order_id' => $o->id, 'type' => 'payment_expired', 'message' => 'The payment window expired. No payment was confirmed.', 'customer_visible' => true]);
                        }
                        $a->update(['status' => 'expired', 'last_checked_at' => now()]);
                    }
                }, 3);

                return $a->fresh();
            }
            if (($payload['status'] ?? null) === 'APPROVED' && $captureApproved && ! $order->canceled_at && $order->stock_status === 'reserved') {
                $a->update(['status' => 'capture_pending', 'capture_requested_at' => $a->capture_requested_at ?? now()]);
                try {
                    $payload = $this->client->capture($config, $a->provider_order_id, substr(hash('sha256', 'capture:'.$a->uuid), 0, 36));
                } catch (Throwable $e) {
                    $a->update(['status' => 'unknown', 'last_error' => 'Capture result requires reconciliation.']);
                    report($e);

                    return $a->fresh();
                }
                $payload = $this->client->inspect($config, $a->provider_order_id);
                $this->validateOrder($a, $payload);
            }
            $captures = data_get($payload, 'purchase_units.0.payments.captures', []);
            abort_if(count($captures) > 1, 409, 'Multiple captures require manual review.');
            $capture = $captures[0] ?? null;
            if ($capture) {
                $this->applyCapture($a, $capture);
            } elseif (($payload['status'] ?? null) === 'VOIDED') {
                DB::transaction(function () use ($a) {
                    $o = Order::whereKey($a->order_id)->lockForUpdate()->firstOrFail();
                    if (! $o->paid_at) {
                        $a->update(['status' => 'canceled']);
                        $this->stock->release($o);
                        $o->update(['payment_status' => 'canceled', 'fulfillment_status' => 'canceled', 'canceled_at' => now()]);
                    }
                }, 3);
            } elseif ($a->capture_requested_at && ! $order->paid_at) {
                $a->update(['status' => 'unknown']);
            } elseif (($payload['status'] ?? null) === 'APPROVED') {
                $a->update(['status' => 'approved']);
            } elseif (! in_array($a->status, ['completed', 'failed', 'canceled'], true)) {
                $a->update(['status' => 'awaiting_approval']);
            }
            $a->update(['last_checked_at' => now()]);

            return $a->fresh();
        });
    }

    public function validateOrder(PaymentAttempt $a, array $payload): void
    {
        $o = $a->order;
        abort_unless(($payload['id'] ?? null) === $a->provider_order_id && count($payload['purchase_units'] ?? []) === 1, 409, 'PayPal order mismatch.');
        $unit = $payload['purchase_units'][0];
        abort_unless(($unit['custom_id'] ?? null) === $o->uuid && ($unit['amount']['currency_code'] ?? null) === 'USD' &&
            Money::cents((string) ($unit['amount']['value'] ?? '')) === $o->total_cents &&
            ($unit['payee']['merchant_id'] ?? null) === $a->gateway_snapshot['credentials']['merchant_id'], 409, 'PayPal amount, currency, merchant or order mismatch.');
    }

    private function applyCapture(PaymentAttempt $attempt, array $capture): void
    {
        abort_unless(($capture['amount']['currency_code'] ?? null) === 'USD' &&
            Money::cents((string) ($capture['amount']['value'] ?? '')) === $attempt->order->total_cents && ! empty($capture['id']), 409, 'Capture amount mismatch.');
        DB::transaction(function () use ($attempt, $capture) {
            $o = Order::whereKey($attempt->order_id)->lockForUpdate()->firstOrFail();
            $a = PaymentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            abort_if($a->capture_id && $a->capture_id !== $capture['id'], 409, 'Capture ID mismatch.');
            $a->capture_id = $capture['id'];
            $status = $capture['status'] ?? '';
            if (in_array($status, ['COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED'], true)) {
                $a->status = 'completed';
                $a->last_error = null;
                if (! $o->paid_at) {
                    $o->paid_at = now();
                    $o->payment_status = 'paid';
                    $o->save();
                    $this->stock->capture($o);
                    if ($o->canceled_at) {
                        $o->update(['fulfillment_status' => 'on_hold']);
                    }
                    OrderEvent::create(['order_id' => $o->id, 'type' => 'paid', 'message' => 'Payment confirmed.', 'customer_visible' => true]);
                    Notification::route('mail', $o->email)->notify(new StoreMail('Payment confirmed — '.$o->reference,
                        ['Your payment has been confirmed. Your order will now be prepared.'], app(OrderAccessService::class)->link($o), 'View order'));
                }
                if ($status === 'REFUNDED') {
                    $o->update(['payment_status' => 'refunded', 'refunded_cents' => $o->total_cents]);
                } elseif ($status === 'PARTIALLY_REFUNDED' && $o->payment_status !== 'refunded') {
                    $o->update(['payment_status' => 'partially_refunded']);
                }
            } elseif ($status === 'PENDING' && ! $o->paid_at) {
                $a->status = 'capture_pending';
            } elseif (in_array($status, ['DECLINED', 'FAILED'], true) && ! $o->paid_at) {
                $a->status = 'failed';
                $this->stock->release($o);
                $o->update(['payment_status' => 'failed', 'fulfillment_status' => 'canceled', 'canceled_at' => now()]);
            }
            $a->save();
        }, 3);
    }

    public function attach(PaymentAttempt $attempt, string $providerId): void
    {
        abort_if($attempt->provider_order_id && $attempt->provider_order_id !== $providerId, 409, 'Provider ID already set.');
        $payload = $this->client->inspect($attempt->gateway_snapshot, $providerId);
        $attempt->provider_order_id = $providerId;
        $this->validateOrder($attempt, $payload);
        $attempt->save();
    }
}
