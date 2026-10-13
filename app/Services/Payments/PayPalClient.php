<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentGateway;
use App\Support\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class PayPalClient
{
    public function __construct(private PayPalCallbackLinkService $callbacks) {}

    public function configuration(string $environment): array
    {
        abort_unless(in_array($environment, ['sandbox', 'live'], true), 422, 'Invalid PayPal environment.');
        $g = PaymentGateway::where('slug', 'paypal')->first();
        $credentials = array_replace(config('store.paypal.'.$environment), ($g?->credentials ?? [])[$environment] ?? []);
        foreach (['client_id', 'client_secret', 'merchant_id', 'webhook_id'] as $key) {
            abort_if(empty($credentials[$key]), 503, 'PayPal is not fully configured.');
        }

        return ['environment' => $environment, 'credentials' => $credentials];
    }

    private function base(array $config): string
    {
        return $config['environment'] === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private function http(array $config): PendingRequest
    {
        $c = $config['credentials'];
        $key = 'paypal:oauth:'.hash('sha256', json_encode($config));
        $token = Cache::remember($key, 300, function () use ($config, $c) {
            $json = Http::asForm()->withBasicAuth($c['client_id'], $c['client_secret'])->connectTimeout(5)->timeout(15)
                ->post($this->base($config).'/v1/oauth2/token', ['grant_type' => 'client_credentials'])->throw()->json();
            abort_if(empty($json['access_token']), 502, 'PayPal authentication failed.');

            return $json['access_token'];
        });

        return Http::withToken($token)->acceptJson()->asJson()->connectTimeout(5)->timeout(15);
    }

    public function create(array $config, Order $order, string $key): array
    {
        $a = $order->shipping_address;

        return $this->http($config)->withHeaders(['PayPal-Request-Id' => $key])
            ->post($this->base($config).'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [['reference_id' => $order->reference, 'custom_id' => $order->uuid, 'invoice_id' => $order->reference,
                    'payee' => ['merchant_id' => $config['credentials']['merchant_id']],
                    'amount' => ['currency_code' => 'USD', 'value' => Money::decimal($order->total_cents)],
                    'shipping' => ['name' => ['full_name' => $a['name']], 'address' => array_filter([
                        'address_line_1' => $a['line1'], 'address_line_2' => $a['line2'] ?? null, 'admin_area_2' => $a['city'],
                        'admin_area_1' => $a['state'], 'postal_code' => $a['postal_code'], 'country_code' => 'US'])]]],
                'payment_source' => ['paypal' => ['experience_context' => [
                    'brand_name' => 'Nella & Vale', 'user_action' => 'PAY_NOW', 'shipping_preference' => 'SET_PROVIDED_ADDRESS',
                    'return_url' => $this->callbacks->url('return', $key),
                    'cancel_url' => $this->callbacks->url('cancel', $key),
                ]]],
            ])->throw()->json();
    }

    public function inspect(array $config, string $id): array
    {
        return $this->http($config)->get($this->base($config).'/v2/checkout/orders/'.rawurlencode($id))->throw()->json();
    }

    public function capture(array $config, string $id, string $key): array
    {
        return $this->http($config)->withHeaders(['PayPal-Request-Id' => $key])->withBody('{}', 'application/json')
            ->post($this->base($config).'/v2/checkout/orders/'.rawurlencode($id).'/capture')->throw()->json();
    }

    public function refund(array $config, string $captureId, int $cents, string $key): array
    {
        return $this->http($config)->withHeaders(['PayPal-Request-Id' => $key, 'Prefer' => 'return=representation'])->post($this->base($config).'/v2/payments/captures/'.rawurlencode($captureId).'/refund',
            ['amount' => ['value' => Money::decimal($cents), 'currency_code' => 'USD']])->throw()->json();
    }

    public function inspectRefund(array $config, string $id): array
    {
        return $this->http($config)->get($this->base($config).'/v2/payments/refunds/'.rawurlencode($id))->throw()->json();
    }

    public function verifyWebhook(array $config, array|object $event, array $headers): bool
    {
        $host = parse_url($headers['cert_url'] ?? '', PHP_URL_HOST);
        abort_unless(parse_url($headers['cert_url'] ?? '', PHP_URL_SCHEME) === 'https' &&
            in_array($host, ['api.paypal.com', 'api-m.paypal.com', 'api.sandbox.paypal.com', 'api-m.sandbox.paypal.com'], true), 400, 'Invalid certificate URL.');
        $data = $headers + ['webhook_id' => $config['credentials']['webhook_id'], 'webhook_event' => $event];
        $response = $this->http($config)->post($this->base($config).'/v1/notifications/verify-webhook-signature', $data)->throw()->json();

        return ($response['verification_status'] ?? null) === 'SUCCESS';
    }
}
