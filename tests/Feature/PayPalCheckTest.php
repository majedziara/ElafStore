<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesStore;
use Tests\TestCase;

class PayPalCheckTest extends TestCase
{
    use CreatesStore,RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareStore();
        Http::preventStrayRequests();
        config(['store.paypal.webhook_base_url' => 'https://shop.example.com']);
    }

    public function test_sandbox_check_confirms_oauth_url_and_events(): void
    {
        Http::fake(['*/oauth2/token' => Http::response(['access_token' => 'test-token']), '*/webhooks/*' => Http::response(['url' => 'https://shop.example.com/api/v1/webhooks/paypal/sandbox', 'event_types' => [['name' => '*']]])]);
        $this->artisan('store:paypal-check')->expectsOutputToContain('Sandbox OAuth: OK')->assertSuccessful();
    }

    public function test_sandbox_check_reports_a_webhook_registered_to_another_application(): void
    {
        Http::fake(['*/oauth2/token' => Http::response(['access_token' => 'test-token']), '*/webhooks/*' => Http::response(['url' => 'https://another.example.com/webhooks', 'event_types' => [['name' => '*']]])]);
        $this->artisan('store:paypal-check')->expectsOutputToContain('Webhook URL does not match this application')->assertFailed();
    }

    public function test_sandbox_check_reports_missing_subscriptions(): void
    {
        Http::fake(['*/oauth2/token' => Http::response(['access_token' => 'test-token']), '*/webhooks/*' => Http::response(['url' => 'https://shop.example.com/api/v1/webhooks/paypal/sandbox', 'event_types' => []])]);
        $this->artisan('store:paypal-check')->expectsOutputToContain('Missing subscriptions')->assertFailed();
    }
}
