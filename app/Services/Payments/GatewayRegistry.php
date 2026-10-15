<?php

namespace App\Services\Payments;

final class GatewayRegistry
{
    public const NAMES = ['paypal' => 'PayPal', 'stripe' => 'Stripe', 'triple-a' => 'Crypto / Triple-A'];

    public function environment(string $gateway): string
    {
        return (string) config($gateway === 'paypal' ? 'store.paypal.mode' : 'payments.'.$gateway.'.mode', 'sandbox');
    }

    public function fields(string $gateway): array
    {
        return match ($gateway) {
            'paypal' => ['client_id', 'client_secret', 'merchant_id', 'webhook_id'],
            'stripe' => ['secret_key', 'webhook_secret'],
            'triple-a' => ['client_id', 'client_secret', 'merchant_key'],
            default => [],
        };
    }

    public function missing(string $gateway, string $environment): array
    {
        $credentials = config(($gateway === 'paypal' ? 'store.paypal' : 'payments.'.$gateway).'.'.$environment, []);

        return array_values(array_filter($this->fields($gateway), fn (string $field): bool => ! is_string($credentials[$field] ?? null) || trim($credentials[$field]) === ''));
    }

    public function ready(string $gateway, ?string $environment = null): bool
    {
        $environment ??= $this->environment($gateway);
        if (! isset(self::NAMES[$gateway]) || ! in_array($environment, ['sandbox', 'live'], true) || $this->missing($gateway, $environment)) {
            return false;
        }
        if ($gateway === 'stripe') {
            $credentials = config('payments.stripe.'.$environment);

            return str_starts_with($credentials['secret_key'], $environment === 'live' ? 'sk_live_' : 'sk_test_') && str_starts_with($credentials['webhook_secret'], 'whsec_');
        }

        if ($gateway === 'triple-a') {
            return strlen((string) config('payments.triple-a.'.$environment.'.notify_secret')) <= 64 && filled(config('app.key'));
        }

        return true;
    }

    public function available(string $gateway): bool
    {
        return (bool) config($gateway === 'paypal' ? 'payments.paypal_enabled' : 'payments.'.$gateway.'.enabled', false) && $this->ready($gateway);
    }

    public function methods(): array
    {
        $methods = [];
        foreach (self::NAMES as $gateway => $name) {
            if ($this->available($gateway)) {
                $methods[] = ['slug' => $gateway, 'name' => $name, 'environment' => $this->environment($gateway)];
            }
        }

        return $methods;
    }

    public function configuration(string $gateway, ?string $environment = null): array
    {
        $environment ??= $this->environment($gateway);
        abort_unless($this->ready($gateway, $environment), 503, 'Payment gateway is not fully configured for this environment.');

        $credentials = config(($gateway === 'paypal' ? 'store.paypal' : 'payments.'.$gateway).'.'.$environment);
        if ($gateway === 'triple-a' && empty($credentials['notify_secret'])) {
            $credentials['notify_secret'] = hash_hmac('sha256', 'triple-a:'.$environment.':webhook', config('app.key'));
        }

        return ['gateway' => $gateway, 'environment' => $environment, 'credentials' => $credentials];
    }

    public function webhookUrl(string $gateway, string $environment): string
    {
        return config('payments.webhook_base_url').'/api/v1/webhooks/'.$gateway.'/'.$environment;
    }
}
