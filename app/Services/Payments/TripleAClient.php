<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Support\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

final class TripleAClient
{
    private const BASE = 'https://api.triple-a.io/api/v2';

    public function __construct(private PaymentCallbackLinkService $callbacks, private GatewayRegistry $gateways) {}

    private function http(array $config): PendingRequest
    {
        $credentials = $config['credentials'];
        $token = Cache::remember('triple-a:oauth:'.hash('sha256', json_encode($config, JSON_THROW_ON_ERROR)), 300, function () use ($credentials): string {
            $payload = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(20)->post(self::BASE.'/oauth/token', [
                'client_id' => $credentials['client_id'], 'client_secret' => $credentials['client_secret'], 'grant_type' => 'client_credentials',
            ])->throw()->json();
            abort_unless(is_string($payload['access_token'] ?? null) && $payload['access_token'] !== '', 502, 'Triple-A authentication failed.');

            return $payload['access_token'];
        });

        return Http::withToken($token)->acceptJson()->asJson()->connectTimeout(5)->timeout(20);
    }

    public function create(array $config, Order $order, string $key): array
    {
        $payload = $this->http($config)->post(self::BASE.'/payment', [
            'type' => 'triplea', 'merchant_key' => $config['credentials']['merchant_key'], 'order_id' => $key,
            'order_currency' => 'USD', 'order_amount' => $order->total_cents / 100,
            'payer_id' => $order->email, 'payer_email' => $order->email, 'payer_name' => $order->billing_address['name'],
            'sandbox' => $config['environment'] === 'sandbox',
            'notify_url' => $this->gateways->webhookUrl('triple-a', $config['environment']), 'notify_secret' => $config['credentials']['notify_secret'],
            'notify_txs' => true, 'webhook_data' => ['order_id' => $order->uuid, 'attempt_uuid' => $key],
            'success_url' => $this->callbacks->url('triple-a', 'return', $key), 'cancel_url' => $this->callbacks->url('triple-a', 'cancel', $key),
        ])->throw()->json();
        abort_unless(($payload['order_currency'] ?? null) === 'USD' && Money::cents((string) ($payload['order_amount'] ?? '')) === $order->total_cents, 409, 'Triple-A order amount mismatch.');

        return ['id' => $payload['payment_reference'] ?? null, 'links' => [['rel' => 'approve', 'href' => $payload['hosted_url'] ?? null]]];
    }

    public function inspect(array $config, string $id): array
    {
        $payload = $this->http($config)->get(self::BASE.'/payment/'.rawurlencode($id), ['verbose' => 1])->throw()->json();
        abort_unless(($payload['payment_reference'] ?? null) === $id && ($payload['type'] ?? null) === 'triplea', 409, 'Triple-A payment identity mismatch.');
        if (isset($payload['merchant_key'])) {
            abort_unless($payload['merchant_key'] === $config['credentials']['merchant_key'], 409, 'Triple-A merchant mismatch.');
        }
        if (isset($payload['crypto_currency'])) {
            abort_unless(str_starts_with(strtolower($payload['crypto_currency']), 'test') === ($config['environment'] === 'sandbox'), 409, 'Triple-A environment mismatch.');
        }
        $state = $this->providerState($payload['success_url'] ?? '');
        $attempt = PaymentAttempt::where('uuid', $state['attempt'])->where('gateway', 'triple-a')->where('environment', $config['environment'])->firstOrFail();
        $unit = ['custom_id' => $attempt->order->uuid, 'amount' => ['currency_code' => $payload['order_currency'] ?? '', 'value' => (string) ($payload['order_amount'] ?? '')]];
        $status = match ($payload['status'] ?? '') {
            'good' => 'COMPLETED', 'hold', 'short' => 'PENDING', 'invalid' => 'FAILED', default => null
        };
        if ($status === 'COMPLETED') {
            abort_unless(($payload['payment_currency'] ?? null) === 'USD' && Money::cents((string) ($payload['receive_amount'] ?? '')) >= Money::cents($unit['amount']['value']), 409, 'Triple-A payment was not fully received.');
        }
        $unit['payments'] = ['captures' => $status ? [['id' => $id, 'status' => $status, 'amount' => $unit['amount']]] : []];

        return ['id' => $id, 'status' => ($payload['status'] ?? null) === 'none' && isset($payload['expires_in']) && $payload['expires_in'] <= 0 ? 'EXPIRED' : 'CREATED', 'purchase_units' => [$unit]];
    }

    private function providerState(string $url): array
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        try {
            $state = json_decode(Crypt::decryptString($query['state'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(409, 'Triple-A order reference is invalid.');
        }
        abort_unless(is_array($state) && ($state['gateway'] ?? null) === 'triple-a' && ($state['action'] ?? null) === 'return' && is_string($state['attempt'] ?? null), 409, 'Triple-A order reference mismatch.');

        return $state;
    }

    public function refund(array $config, PaymentAttempt $attempt, int $cents, string $key): array
    {
        $payload = $this->http($config)->post(self::BASE.'/payout/refund/local', [
            'payment_reference' => $attempt->capture_id, 'email' => $attempt->order->email, 'refund_amount' => $cents / 100,
            'refund_reason' => 'requested_by_customer', 'remarks' => 'Store refund '.$key,
            'notify_url' => $this->gateways->webhookUrl('triple-a', $config['environment']), 'notify_secret' => $config['credentials']['notify_secret'],
        ])->throw()->json();

        return $this->normalizeRefund($payload, $attempt->capture_id);
    }

    public function inspectRefund(array $config, string $id, ?string $captureId = null): array
    {
        abort_unless($captureId, 409, 'Triple-A refund needs its original payment reference.');
        $refunds = $this->paymentRefunds($config, $captureId);
        abort_unless(collect($refunds['refunds'] ?? [])->contains(fn (array $refund): bool => ($refund['payout_reference'] ?? null) === $id), 409, 'Triple-A refund does not belong to this payment.');
        $payload = $this->http($config)->get(self::BASE.'/payout/refund/'.rawurlencode($id))->throw()->json();
        abort_unless(($payload['payout_reference'] ?? null) === $id, 409, 'Triple-A refund identity mismatch.');

        return $this->normalizeRefund($payload, $captureId);
    }

    public function paymentRefunds(array $config, string $captureId): array
    {
        return $this->http($config)->get(self::BASE.'/payment/'.rawurlencode($captureId).'/refunds')->throw()->json();
    }

    private function normalizeRefund(array $payload, string $captureId): array
    {
        abort_unless(($payload['type'] ?? null) === 'refund', 409, 'Triple-A payout is not a refund.');

        return ['id' => $payload['payout_reference'] ?? null, 'capture_id' => $captureId,
            'amount' => ['value' => (string) ($payload['local_amount'] ?? ''), 'currency_code' => $payload['local_currency'] ?? ''],
            'status' => match ($payload['status'] ?? '') {
                'done' => 'COMPLETED', 'cancel' => 'FAILED', default => 'PENDING'
            },
        ];
    }
}
