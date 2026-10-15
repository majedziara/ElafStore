<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Support\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class StripeClient
{
    public function __construct(private PaymentCallbackLinkService $callbacks) {}

    private function http(array $config): PendingRequest
    {
        return Http::withBasicAuth($config['credentials']['secret_key'], '')->withHeaders(['Stripe-Version' => config('payments.stripe.api_version')])
            ->acceptJson()->asForm()->connectTimeout(5)->timeout(20);
    }

    public function create(array $config, Order $order, string $key): array
    {
        $payload = $this->http($config)->withHeaders(['Idempotency-Key' => $key])->post('https://api.stripe.com/v1/checkout/sessions', [
            'mode' => 'payment', 'payment_method_types' => ['card'], 'client_reference_id' => $order->uuid,
            'customer_email' => $order->email, 'metadata' => ['order_uuid' => $order->uuid, 'attempt_uuid' => $key],
            'payment_intent_data' => ['metadata' => ['order_uuid' => $order->uuid, 'attempt_uuid' => $key]],
            'line_items' => [['price_data' => ['currency' => 'usd', 'unit_amount' => $order->total_cents,
                'product_data' => ['name' => 'Nella & Vale order '.$order->reference]], 'quantity' => 1]],
            'expires_at' => max(now()->addMinutes(30)->timestamp, $order->reservation_expires_at->timestamp),
            'success_url' => $this->callbacks->url('stripe', 'return', $key),
            'cancel_url' => $this->callbacks->url('stripe', 'cancel', $key),
        ])->throw()->json();
        abort_unless(($payload['livemode'] ?? null) === ($config['environment'] === 'live'), 409, 'Stripe environment mismatch.');

        return ['id' => $payload['id'] ?? null, 'links' => [['rel' => 'approve', 'href' => $payload['url'] ?? null]]];
    }

    public function inspect(array $config, string $id): array
    {
        $payload = $this->http($config)->get('https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($id), ['expand' => ['payment_intent.latest_charge']])->throw()->json();
        abort_unless(($payload['id'] ?? null) === $id && ($payload['mode'] ?? null) === 'payment' &&
            ($payload['livemode'] ?? null) === ($config['environment'] === 'live'), 409, 'Stripe session mismatch.');
        $unit = ['custom_id' => $payload['client_reference_id'] ?? null,
            'amount' => ['currency_code' => strtoupper($payload['currency'] ?? ''), 'value' => Money::decimal((int) ($payload['amount_total'] ?? -1))]];
        $captures = [];
        $intent = $payload['payment_intent'] ?? null;
        if (is_array($intent)) {
            abort_unless(($intent['livemode'] ?? null) === ($config['environment'] === 'live') &&
                ($intent['currency'] ?? null) === ($payload['currency'] ?? null) && ($intent['amount'] ?? null) === ($payload['amount_total'] ?? null) &&
                data_get($intent, 'metadata.order_uuid') === $unit['custom_id'], 409, 'Stripe payment identity mismatch.');
            $status = 'PENDING';
            $charge = $intent['latest_charge'] ?? null;
            if (($payload['payment_status'] ?? null) === 'paid') {
                abort_unless(($intent['status'] ?? null) === 'succeeded' && ($intent['amount_received'] ?? null) === ($payload['amount_total'] ?? null) &&
                    is_array($charge) && ($charge['paid'] ?? false) === true && ($charge['amount'] ?? null) === ($payload['amount_total'] ?? null) &&
                    ($charge['currency'] ?? null) === ($payload['currency'] ?? null), 409, 'Stripe payment has not been fully received.');
                $status = ($charge['disputed'] ?? false) ? 'REVERSED' : (($charge['amount_refunded'] ?? 0) === $payload['amount_total'] ? 'REFUNDED' : (($charge['amount_refunded'] ?? 0) > 0 ? 'PARTIALLY_REFUNDED' : 'COMPLETED'));
            } elseif (($intent['status'] ?? null) === 'canceled') {
                $status = 'FAILED';
            }
            if ($status !== 'PENDING' || ($intent['status'] ?? null) === 'processing') {
                $captures[] = ['id' => $intent['id'], 'status' => $status, 'amount' => $unit['amount'], 'refunded_cents' => is_array($charge) ? (int) ($charge['amount_refunded'] ?? 0) : 0];
            }
        }
        abort_if(($payload['payment_status'] ?? null) === 'paid' && ! $captures, 409, 'Stripe paid session is missing its payment.');
        $unit['payments'] = ['captures' => $captures];

        return ['id' => $id, 'status' => ($payload['status'] ?? null) === 'expired' && ! $captures ? 'EXPIRED' : 'CREATED', 'purchase_units' => [$unit]];
    }

    public function refund(array $config, PaymentAttempt $attempt, int $cents, string $key): array
    {
        $payload = $this->http($config)->withHeaders(['Idempotency-Key' => $key])->post('https://api.stripe.com/v1/refunds', [
            'payment_intent' => $attempt->capture_id, 'amount' => $cents, 'metadata' => ['request_key' => $key, 'order_uuid' => $attempt->order->uuid],
        ])->throw()->json();

        return $this->normalizeRefund($config, $payload, $attempt->capture_id);
    }

    public function inspectRefund(array $config, string $id, ?string $captureId = null): array
    {
        $payload = $this->http($config)->get('https://api.stripe.com/v1/refunds/'.rawurlencode($id))->throw()->json();
        abort_unless(($payload['id'] ?? null) === $id, 409, 'Stripe refund identity mismatch.');

        return $this->normalizeRefund($config, $payload, $captureId);
    }

    private function normalizeRefund(array $config, array $payload, ?string $captureId): array
    {
        abort_unless(is_string($payload['payment_intent'] ?? null) && (! $captureId || $payload['payment_intent'] === $captureId), 409, 'Stripe refund payment mismatch.');

        return ['id' => $payload['id'] ?? null, 'capture_id' => $payload['payment_intent'], 'request_key' => data_get($payload, 'metadata.request_key'),
            'amount' => ['value' => Money::decimal((int) ($payload['amount'] ?? -1)), 'currency_code' => strtoupper($payload['currency'] ?? '')],
            'status' => match ($payload['status'] ?? '') {
                'succeeded' => 'COMPLETED', 'failed', 'canceled' => 'FAILED', default => 'PENDING'
            },
        ];
    }
}
