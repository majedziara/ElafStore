<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentAttempt;

final class GatewayClient
{
    public function __construct(private PayPalClient $paypal, private StripeClient $stripe, private TripleAClient $tripleA, private GatewayRegistry $gateways) {}

    public function configuration(string $gateway, ?string $environment = null): array
    {
        return $this->gateways->configuration($gateway, $environment);
    }

    public function create(array $config, Order $order, string $key): array
    {
        return $this->driver($config)->create($config, $order, $key);
    }

    public function inspect(array $config, string $id): array
    {
        return $this->driver($config)->inspect($config, $id);
    }

    public function capture(array $config, string $id, string $key): array
    {
        abort_unless(($config['gateway'] ?? 'paypal') === 'paypal', 409, 'This payment gateway captures payments through hosted checkout.');

        return $this->paypal->capture($config, $id, $key);
    }

    public function refund(array $config, PaymentAttempt $attempt, int $cents, string $key): array
    {
        return ($config['gateway'] ?? 'paypal') === 'paypal'
            ? $this->paypal->refund($config, $attempt->capture_id, $cents, $key)
            : $this->driver($config)->refund($config, $attempt, $cents, $key);
    }

    public function inspectRefund(array $config, string $id, ?string $captureId = null): array
    {
        return ($config['gateway'] ?? 'paypal') === 'paypal'
            ? $this->paypal->inspectRefund($config, $id)
            : $this->driver($config)->inspectRefund($config, $id, $captureId);
    }

    public function validApprovalUrl(string $gateway, mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 8192) {
            return false;
        }
        $url = parse_url($value);
        $hosts = match ($gateway) {
            'paypal' => ['www.paypal.com', 'www.sandbox.paypal.com'],
            'stripe' => ['checkout.stripe.com'],
            'triple-a' => ['triple-a.io', 'www.triple-a.io', 'cdn.triple-a.io', 'pay.triple-a.io'],
            default => [],
        };

        return is_array($url) && ($url['scheme'] ?? null) === 'https' && in_array(strtolower($url['host'] ?? ''), $hosts, true) &&
            ! isset($url['user']) && ! isset($url['pass']) && (! isset($url['port']) || $url['port'] === 443);
    }

    private function driver(array $config): PayPalClient|StripeClient|TripleAClient
    {
        return match ($config['gateway'] ?? 'paypal') {
            'paypal' => $this->paypal, 'stripe' => $this->stripe, 'triple-a' => $this->tripleA,
            default => abort(503, 'Payment gateway is unavailable.'),
        };
    }
}
