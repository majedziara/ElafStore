<?php

namespace App\Console\Commands;

use App\Services\Payments\PayPalClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class CheckPayPalSandbox extends Command
{
    protected $signature = 'store:paypal-check';

    protected $description = 'Check sandbox OAuth credentials and webhook registration without making a payment';

    public function handle(PayPalClient $client): int
    {
        try {
            $config = $client->configuration('sandbox');
            $credentials = $config['credentials'];
            $response = Http::asForm()->withBasicAuth($credentials['client_id'], $credentials['client_secret'])->connectTimeout(5)->timeout(20)->post('https://api-m.sandbox.paypal.com/v1/oauth2/token', ['grant_type' => 'client_credentials']);
            if (! $response->successful() || ! $response->json('access_token')) {
                $this->error('Sandbox authentication failed (HTTP '.$response->status().').');

                return self::FAILURE;
            }
            $this->info('Sandbox OAuth: OK');
            $webhook = Http::withToken($response->json('access_token'))->acceptJson()->connectTimeout(5)->timeout(20)->get('https://api-m.sandbox.paypal.com/v1/notifications/webhooks/'.rawurlencode($credentials['webhook_id']));
            if (! $webhook->successful()) {
                $this->error('Webhook registration lookup failed (HTTP '.$webhook->status().').');

                return self::FAILURE;
            }
            $this->line('Registered webhook URL: '.$webhook->json('url'));
            $expected = config('store.paypal.webhook_base_url').'/api/v1/webhooks/paypal/sandbox';
            $matches = $webhook->json('url') === $expected;
            if (! $matches) {
                $this->warn('Webhook URL does not match this application: '.$expected);
            }
            $events = collect($webhook->json('event_types', []))->pluck('name');
            $required = collect(['CHECKOUT.ORDER.APPROVED', 'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED']);
            $missing = $events->contains('*') ? collect() : $required->diff($events);
            if ($missing->isNotEmpty()) {
                $this->warn('Missing subscriptions: '.$missing->implode(', '));

                return self::FAILURE;
            }
            $this->info('Webhook event subscriptions: OK');

            return $matches ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->error('PayPal check failed: '.class_basename($e).'. Check connectivity and configuration.');

            return self::FAILURE;
        }
    }
}
