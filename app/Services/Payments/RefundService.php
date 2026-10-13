<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class RefundService
{
    public function __construct(private PayPalClient $client) {}

    public function create(Order $order, array $data, int $actor): Refund
    {
        return Cache::lock('order-payment:'.$order->id, 120)->block(5, function () use ($order, $data, $actor) {
            $new = false;
            $refund = DB::transaction(function () use ($order, $data, $actor, &$new) {
                $o = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $old = Refund::where('request_key', $data['request_key'])->first();
                if ($old) {
                    abort_unless($old->order_id === $o->id && $old->amount_cents === (int) $data['amount_cents'] && $old->reason === $data['reason'] && $old->return_request_id === (isset($data['return_request_id']) ? (int) $data['return_request_id'] : null), 409, 'Refund idempotency key mismatch.');

                    return $old;
                }
                abort_unless($o->paid_at && in_array($o->payment_status, ['paid', 'partially_refunded'], true), 422, 'Order is not refundable.');
                abort_if($o->refunds()->whereIn('status', ['pending', 'unknown'])->exists(), 409, 'An unresolved refund already exists.');
                $a = $o->payments()->where('status', 'completed')->whereNotNull('capture_id')->latest('id')->firstOrFail();
                abort_if((int) $data['amount_cents'] > $o->total_cents - $o->refunded_cents, 422, 'Refund exceeds remaining paid amount.');
                $rrId = $data['return_request_id'] ?? null;
                if ($rrId) {
                    $rr = ReturnRequest::whereKey($rrId)->where('order_id', $o->id)->lockForUpdate()->firstOrFail();
                    abort_unless($rr->type === 'return' && $rr->status === 'received', 422, 'Return is not ready for a refund.');
                }
                $new = true;
                $refund = Refund::create(['request_key' => $data['request_key'], 'order_id' => $o->id, 'payment_attempt_id' => $a->id,
                    'amount_cents' => $data['amount_cents'], 'reason' => $data['reason'], 'return_request_id' => $rrId]);
                OrderEvent::create(['order_id' => $o->id, 'actor_id' => $actor, 'type' => 'refund_requested', 'message' => $data['reason']]);

                return $refund;
            }, 3);
            if (! $new) {
                return $refund;
            }
            $a = PaymentAttempt::findOrFail($refund->payment_attempt_id);
            try {
                $payload = $this->client->refund($a->gateway_snapshot, $a->capture_id, $refund->amount_cents, $refund->request_key);
                abort_if(empty($payload['id']), 502, 'Refund ID missing.');
                $refund->update(['provider_refund_id' => $payload['id']]);
                $this->apply($refund, $payload);
            } catch (Throwable $e) {
                $refund->update(['status' => 'unknown']);
                report($e);
            }

            return $refund->fresh();
        });
    }

    public function sync(Refund $refund): Refund
    {
        abort_unless($refund->provider_refund_id, 409, 'Refund result is unknown. Reconcile in PayPal before attaching a provider refund ID.');
        $a = PaymentAttempt::findOrFail($refund->payment_attempt_id);
        $payload = $this->client->inspectRefund($a->gateway_snapshot, $refund->provider_refund_id);
        $this->apply($refund, $payload);

        return $refund->fresh();
    }

    public function import(string $environment, string $id): void
    {
        $config = $this->client->configuration($environment);
        $payload = $this->client->inspectRefund($config, $id);
        $captureId = null;
        foreach ($payload['links'] ?? [] as $link) {
            if (($link['rel'] ?? null) === 'up' && preg_match('~/v2/payments/captures/([A-Za-z0-9]+)$~', $link['href'] ?? '', $m)) {
                $captureId = $m[1];
            }
        }
        if (! $captureId) {
            return;
        }
        $a = PaymentAttempt::where('capture_id', $captureId)->where('environment', $environment)->first();
        if (! $a) {
            return;
        }
        $refund = Refund::firstOrCreate(['provider_refund_id' => $id], ['request_key' => (string) Str::uuid(), 'order_id' => $a->order_id,
            'payment_attempt_id' => $a->id, 'amount_cents' => Money::cents((string) data_get($payload, 'amount.value', '')), 'reason' => 'Provider refund']);
        $this->apply($refund, $payload);
    }

    public function attach(Refund $refund, string $id): void
    {
        $a = PaymentAttempt::findOrFail($refund->payment_attempt_id);
        $payload = $this->client->inspectRefund($a->gateway_snapshot, $id);
        $up = collect($payload['links'] ?? [])->first(fn ($l) => ($l['rel'] ?? null) === 'up');
        abort_unless(str_ends_with($up['href'] ?? '', '/v2/payments/captures/'.$a->capture_id) && data_get($payload, 'amount.currency_code') === 'USD' &&
            Money::cents((string) data_get($payload, 'amount.value', '')) === $refund->amount_cents, 409, 'Refund does not belong to this capture or amount.');
        DB::transaction(function () use ($refund, $id) {
            Order::whereKey($refund->order_id)->lockForUpdate()->firstOrFail();
            $other = Refund::where('provider_refund_id', $id)->where('id', '!=', $refund->id)->lockForUpdate()->first();
            if ($other) {
                abort_unless($other->order_id === $refund->order_id && $other->amount_cents === $refund->amount_cents && $other->reason === 'Provider refund', 409, 'Provider refund already belongs to another request.');
                $other->update(['provider_refund_id' => null, 'status' => 'merged']);
            }
            $refund->provider_refund_id = $id;
            $refund->save();
        }, 3);
        $this->apply($refund, $payload);
    }

    private function apply(Refund $refund, array $payload): void
    {
        abort_unless(($payload['id'] ?? null) === $refund->provider_refund_id && data_get($payload, 'amount.currency_code') === 'USD' &&
            Money::cents((string) data_get($payload, 'amount.value', '')) === $refund->amount_cents, 409, 'Refund amount mismatch.');
        DB::transaction(function () use ($refund, $payload) {
            $o = Order::whereKey($refund->order_id)->lockForUpdate()->firstOrFail();
            $r = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $was = $r->status;
            $next = match ($payload['status'] ?? '') {
                'COMPLETED' => 'completed','FAILED','CANCELLED' => 'failed',default => 'pending'
            };
            if ($was === 'completed') {
                return;
            }
            $r->update(['status' => $next]);
            $sum = (int) Refund::where('order_id', $o->id)->where('status', 'completed')->sum('amount_cents');
            abort_if($sum > $o->total_cents, 409, 'Refund total exceeds payment.');
            $o->refunded_cents = max($o->refunded_cents, $sum);
            if ($o->refunded_cents > 0) {
                $o->payment_status = $o->refunded_cents === $o->total_cents ? 'refunded' : 'partially_refunded';
            }
            $o->save();
            if ($next === 'completed' && $was !== 'completed') {
                OrderEvent::create(['order_id' => $o->id, 'type' => 'refunded', 'message' => 'Refund confirmed: USD '.Money::decimal($r->amount_cents).'.', 'customer_visible' => true]);
            }
        }, 3);
    }
}
