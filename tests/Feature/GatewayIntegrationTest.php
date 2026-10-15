<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentGateway;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\WebhookEvent;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\PaymentService;
use App\Services\SettingService;
use Database\Seeders\LocalSandboxSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesStore;
use Tests\TestCase;

class GatewayIntegrationTest extends TestCase
{
    use CreatesStore, RefreshDatabase;

    private ?Order $order = null;

    private string $gateway = 'stripe';

    private string $environment = 'sandbox';

    private string $status = 'unpaid';

    private array $created = [];

    private array $overrides = [];

    private int $createCalls = 0;

    private bool $timeout = false;

    private bool $refundTimeout = false;

    private string $refundStatus = 'pending';

    private int $amount = 1000;

    private string $refundRequestKey = '';

    private bool $wrongRefundPayment = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareStore();
        Http::preventStrayRequests();
        config(['store.paypal.mode' => 'sandbox', 'payments.paypal_enabled' => false]);
        foreach (['sandbox', 'live'] as $environment) {
            config(['payments.stripe.'.$environment => ['secret_key' => 'sk_'.($environment === 'live' ? 'live' : 'test').'_private', 'webhook_secret' => 'whsec_private_'.$environment],
                'payments.triple-a.'.$environment => ['client_id' => 'private-client-'.$environment, 'client_secret' => 'private-secret-'.$environment,
                    'merchant_key' => 'mkey-'.$environment, 'notify_secret' => 'notify-'.$environment]]);
        }
    }

    private function start(string $gateway = 'stripe', string $environment = 'sandbox'): array
    {
        $this->gateway = $gateway;
        $this->environment = $environment;
        config(['payments.'.$gateway.'.mode' => $environment]);
        [$this->order] = $this->checkout();
        $this->fakeProvider();
        $data = $this->postJson('/api/v1/orders/'.$this->order->uuid.'/payments/'.$gateway)->assertOk()->json('data');

        return [$data, PaymentAttempt::where('uuid', $data['uuid'])->firstOrFail()];
    }

    private function fakeProvider(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_ends_with($url, '/oauth/token')) {
                return Http::response(['access_token' => 'private-oauth-token']);
            }
            if ($request->method() === 'POST' && (str_ends_with($url, '/checkout/sessions') || str_ends_with($url, '/payment'))) {
                $this->createCalls++;
                $this->created = $request->data();
                if ($this->timeout) {
                    throw new ConnectionException('Creation response lost');
                }

                return Http::response($this->gateway === 'stripe'
                    ? ['id' => 'cs_'.$this->environment, 'url' => 'https://checkout.stripe.com/c/pay/cs_'.$this->environment, 'livemode' => $this->environment === 'live']
                    : ['payment_reference' => 'AAA-100-PMT', 'order_currency' => 'USD', 'order_amount' => $this->order->total_cents / 100,
                        'hosted_url' => 'https://triple-a.io/app/v1/payment_form?payment_reference=AAA-100-PMT&access_token=payment-scope-token']);
            }
            if ($request->method() === 'POST' && (str_ends_with($url, '/v1/refunds') || str_ends_with($url, '/payout/refund/local'))) {
                $this->refundRequestKey = $this->gateway === 'stripe' ? data_get($request->data(), 'metadata.request_key') : str_replace('Store refund ', '', $request['remarks']);
                if ($this->refundTimeout) {
                    throw new ConnectionException('Refund response lost');
                }

                return Http::response($this->refundPayload());
            }
            if (str_contains($url, '/v1/refunds/') || str_contains($url, '/payout/refund/')) {
                return Http::response($this->refundPayload());
            }
            if (str_ends_with($url, '/refunds')) {
                return Http::response(['refunds' => [$this->refundPayload()]]);
            }
            if (str_contains($url, '/checkout/sessions/') || str_contains($url, '/payment/')) {
                return Http::response($this->paymentPayload());
            }
            throw new \RuntimeException('Unexpected provider endpoint: '.$url);
        });
    }

    private function paymentPayload(): array
    {
        if ($this->gateway === 'stripe') {
            $paid = $this->status === 'paid';
            $payload = ['id' => 'cs_'.$this->environment, 'mode' => 'payment', 'livemode' => $this->environment === 'live',
                'client_reference_id' => $this->order->uuid, 'currency' => 'usd', 'amount_total' => $this->order->total_cents,
                'status' => $this->status === 'expired' ? 'expired' : ($paid ? 'complete' : 'open'), 'payment_status' => $paid ? 'paid' : 'unpaid',
                'payment_intent' => $paid || $this->status === 'processing' ? ['id' => 'pi_'.$this->environment, 'livemode' => $this->environment === 'live',
                    'currency' => 'usd', 'amount' => $this->order->total_cents, 'amount_received' => $paid ? $this->order->total_cents : 0,
                    'status' => $paid ? 'succeeded' : 'processing', 'metadata' => ['order_uuid' => $this->order->uuid],
                    'latest_charge' => ['paid' => $paid, 'amount' => $this->order->total_cents, 'currency' => 'usd', 'amount_refunded' => 0, 'disputed' => false]] : null];
        } else {
            $payload = ['payment_reference' => 'AAA-100-PMT', 'type' => 'triplea', 'order_currency' => 'USD', 'order_amount' => $this->order->total_cents / 100,
                'status' => $this->status, 'success_url' => $this->created['success_url'], 'crypto_currency' => $this->environment === 'live' ? 'USDT' : 'testBTC',
                'payment_currency' => 'USD', 'receive_amount' => $this->order->total_cents / 100, 'expires_in' => 1200];
        }
        foreach ($this->overrides as $key => $value) {
            data_set($payload, $key, $value);
        }

        return $payload;
    }

    private function refundPayload(): array
    {
        return $this->gateway === 'stripe' ? ['id' => 're_'.$this->environment, 'payment_intent' => $this->wrongRefundPayment ? 'pi_wrong' : 'pi_'.$this->environment, 'metadata' => ['request_key' => $this->refundRequestKey], 'amount' => $this->amount,
            'currency' => 'usd', 'status' => $this->refundStatus]
            : ['payout_reference' => 'AAA-100-PYT', 'type' => 'refund', 'local_currency' => 'USD', 'local_amount' => $this->amount / 100, 'status' => $this->refundStatus];
    }

    public function test_empty_active_environment_hides_gateway_even_with_database_keys(): void
    {
        config(['payments.stripe.sandbox.secret_key' => null, 'payments.triple-a.sandbox.client_secret' => '']);
        PaymentGateway::where('slug', 'stripe')->update(['enabled' => true, 'credentials' => ['sandbox' => ['secret_key' => 'sk_test_database']]]);
        $this->getJson('/api/v1/payment-methods')->assertOk()->assertJsonCount(0, 'data');
        config(['payments.stripe.mode' => 'live']);
        $this->getJson('/api/v1/payment-methods')->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'stripe')->assertJsonPath('data.0.environment', 'live');
        config(['payments.stripe.live.secret_key' => 'sk_test_wrong-environment']);
        $this->getJson('/api/v1/payment-methods')->assertJsonCount(0, 'data');
    }

    public function test_public_and_admin_responses_never_include_payment_secrets(): void
    {
        $public = $this->getJson('/api/v1/payment-methods')->assertJsonCount(2, 'data');
        $admin = $this->actingAs($this->admin())->getJson('/api/v1/admin/gateways')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.1.configuration_source', 'env');
        foreach (['sk_test_private', 'whsec_private_sandbox', 'private-secret-sandbox', 'notify-sandbox'] as $secret) {
            $this->assertStringNotContainsString($secret, $public->getContent());
            $this->assertStringNotContainsString($secret, $admin->getContent());
        }
        $this->putJson('/api/v1/admin/gateways/stripe', ['credentials' => ['sandbox' => ['secret_key' => 'overwrite']]])->assertUnprocessable();
        config(['payments.stripe.enabled' => false]);
        $this->getJson('/api/v1/payment-methods')->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'triple-a');
    }

    public static function gateways(): array
    {
        return [['stripe', 'sandbox'], ['stripe', 'live'], ['triple-a', 'sandbox'], ['triple-a', 'live']];
    }

    #[DataProvider('gateways')]
    public function test_hosted_creation_callback_verification_and_stock_are_idempotent(string $gateway, string $environment): void
    {
        [$data, $attempt] = $this->start($gateway, $environment);
        $url = '/api/v1/orders/'.$this->order->uuid.'/payments/'.$gateway;
        $this->postJson($url)->assertOk()->assertJsonPath('data.uuid', $attempt->uuid);
        $this->getJson($data['return_url'].'&status=paid&order_amount=0.01')->assertStatus(202);
        $this->assertNull($this->order->fresh()->paid_at);
        $this->status = $gateway === 'stripe' ? 'paid' : 'good';
        $this->getJson($data['return_url'])->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $this->getJson($data['return_url'])->assertOk();
        $this->assertSame(19, ProductVariant::first()->stock);
        $this->assertSame(0, ProductVariant::first()->reserved);
        $this->assertSame(1, $this->order->events()->where('type', 'paid')->count());
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertStringNotContainsString('private', json_encode($attempt->fresh()));
        Http::assertSentCount($gateway === 'stripe' ? 4 : 5);
        if ($gateway === 'stripe') {
            $this->assertSame($this->order->total_cents, $this->created['line_items'][0]['price_data']['unit_amount']);
            Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->hasHeader('Idempotency-Key', $attempt->uuid));
        } else {
            $this->assertSame($environment === 'sandbox', $this->created['sandbox']);
            $this->assertSame($this->order->uuid, $this->created['webhook_data']['order_id']);
            $this->assertStringContainsString('/webhooks/triple-a/'.$environment, $this->created['notify_url']);
        }
    }

    public static function mismatches(): array
    {
        return [['stripe', 'amount_total', 1], ['stripe', 'currency', 'eur'], ['stripe', 'client_reference_id', 'another-order'],
            ['stripe', 'livemode', true], ['stripe', 'payment_intent.amount_received', 1], ['stripe', 'payment_intent.metadata.order_uuid', 'another-order'],
            ['triple-a', 'order_amount', 0.01], ['triple-a', 'order_currency', 'EUR'], ['triple-a', 'merchant_key', 'another-merchant'],
            ['triple-a', 'receive_amount', 0.01], ['triple-a', 'crypto_currency', 'USDT'], ['triple-a', 'success_url', 'https://attacker.invalid/?state=forged']];
    }

    #[DataProvider('mismatches')]
    public function test_provider_identity_environment_currency_and_received_amount_must_match(string $gateway, string $field, mixed $value): void
    {
        $this->start($gateway);
        $this->status = $gateway === 'stripe' ? 'paid' : 'good';
        $this->overrides = [$field => $value];
        $this->postJson('/api/v1/orders/'.$this->order->uuid.'/payments/capture')->assertConflict();
        $this->assertNull($this->order->fresh()->paid_at);
        $this->assertSame(20, ProductVariant::first()->stock);
    }

    public function test_crypto_short_and_hold_never_confirm_payment(): void
    {
        $this->start('triple-a');
        foreach (['short', 'hold'] as $status) {
            $this->status = $status;
            $this->postJson('/api/v1/orders/'.$this->order->uuid.'/payments/capture')->assertOk()->assertJsonPath('data.status', 'capture_pending');
            $this->assertNull($this->order->fresh()->paid_at);
        }
        $this->assertSame(1, ProductVariant::first()->reserved);
    }

    public function test_cancellation_return_does_not_imply_payment_failure(): void
    {
        [$data] = $this->start();
        $this->getJson($data['cancel_url'])->assertStatus(202)->assertJsonPath('data.checkout_canceled', true);
        $this->assertNull($this->order->fresh()->canceled_at);
        $this->getJson(str_replace('/cancel?', '/return?', $data['cancel_url']))->assertNotFound();
        $this->getJson(str_replace('/stripe/', '/triple-a/', $data['return_url']))->assertNotFound();
        $this->travel(121)->minutes();
        $this->getJson($data['return_url'])->assertStatus(410);
    }

    public function test_provider_expiry_releases_stock_and_late_payment_is_held(): void
    {
        [$data, $attempt] = $this->start();
        $this->status = 'expired';
        $this->getJson($data['return_url'])->assertOk()->assertJsonPath('data.payment_status', 'expired');
        $this->assertSame(0, ProductVariant::first()->reserved);
        $this->status = 'paid';
        app(PaymentService::class)->sync($attempt, false);
        $this->assertSame('paid', $this->order->fresh()->payment_status);
        $this->assertSame('on_hold', $this->order->fresh()->fulfillment_status);
    }

    public function test_missing_keys_reject_start_and_switching_gateway_cannot_create_duplicate_payment(): void
    {
        $this->start();
        $this->postJson('/api/v1/orders/'.$this->order->uuid.'/payments/triple-a')->assertConflict();
        $this->postJson('/api/v1/orders/'.$this->order->uuid.'/payments/not-allowed')->assertNotFound();
        $this->assertDatabaseCount('payment_attempts', 1);
        config(['payments.stripe.sandbox.secret_key' => null]);
        $this->status = 'paid';
        $this->postJson('/api/v1/orders/'.$this->order->uuid.'/payments/capture')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->getJson('/api/v1/payment-methods')->assertJsonCount(1, 'data');
    }

    public function test_ambiguous_creation_is_not_repeated(): void
    {
        $this->timeout = true;
        [$data] = $this->start();
        $this->assertSame('unknown', $data['status']);
        $this->postJson('/api/v1/orders/'.$this->order->uuid.'/payments/stripe')->assertJsonPath('data.status', 'unknown');
        $this->assertSame(1, $this->createCalls);
        $this->assertSame(1, ProductVariant::first()->reserved);
    }

    #[DataProvider('gateways')]
    public function test_refunds_stay_pending_until_provider_confirmation_and_are_idempotent(string $gateway, string $environment): void
    {
        $this->start($gateway, $environment);
        $this->status = $gateway === 'stripe' ? 'paid' : 'good';
        $this->postJson('/api/v1/orders/'.$this->order->uuid.'/payments/capture')->assertOk();
        $this->actingAs($this->admin());
        $url = '/api/v1/admin/orders/'.$this->order->uuid.'/refunds';
        $body = ['request_key' => (string) Str::uuid(), 'amount_cents' => 1000, 'reason' => 'Partial refund'];
        $data = $this->postJson($url, $body)->assertOk()->assertJsonPath('data.status', 'pending')->json('data');
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.id', $data['id']);
        $this->assertSame(0, $this->order->fresh()->refunded_cents);
        $this->postJson($url, array_replace($body, ['request_key' => (string) Str::uuid()]))->assertConflict();
        $this->refundStatus = $gateway === 'stripe' ? 'succeeded' : 'done';
        $this->postJson('/api/v1/admin/refunds/'.$data['id'].'/sync')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->postJson('/api/v1/admin/refunds/'.$data['id'].'/sync')->assertOk();
        $this->assertSame(1000, $this->order->fresh()->refunded_cents);
        $this->assertSame('partially_refunded', $this->order->fresh()->payment_status);
        $this->assertDatabaseCount('refunds', 1);
        $this->postJson($url, array_replace($body, ['request_key' => (string) Str::uuid(), 'amount_cents' => $this->order->total_cents]))->assertUnprocessable();
    }

    public function test_refund_timeout_is_not_repeated(): void
    {
        $this->start();
        $this->status = 'paid';
        $this->postJson('/api/v1/orders/'.$this->order->uuid.'/payments/capture')->assertOk();
        $this->actingAs($this->admin());
        $this->refundTimeout = true;
        $body = ['request_key' => (string) Str::uuid(), 'amount_cents' => 1000, 'reason' => 'Lost refund response'];
        $url = '/api/v1/admin/orders/'.$this->order->uuid.'/refunds';
        $data = $this->postJson($url, $body)->assertOk()->assertJsonPath('data.status', 'unknown')->json('data');
        $this->postJson($url, $body)->assertOk();
        $this->assertDatabaseCount('refunds', 1);
        $this->postJson('/api/v1/admin/refunds/'.$data['id'].'/sync')->assertConflict();
        $this->assertSame(0, $this->order->fresh()->refunded_cents);
    }

    #[DataProvider('gateways')]
    public function test_signed_webhooks_verify_with_provider_and_deduplicate(string $gateway, string $environment): void
    {
        [$data, $attempt] = $this->start($gateway, $environment);
        $this->status = $gateway === 'stripe' ? 'paid' : 'good';
        $event = $gateway === 'stripe' ? ['id' => 'evt_1', 'type' => 'checkout.session.completed', 'livemode' => $environment === 'live', 'data' => ['object' => ['id' => 'cs_'.$environment]]]
            : ['event' => 'payment', 'status' => 'good', 'payment_reference' => 'AAA-100-PMT', 'merchant_key' => 'mkey-'.$environment];
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $time = now()->timestamp;
        $secret = config('payments.'.$gateway.'.'.$environment.'.'.($gateway === 'stripe' ? 'webhook_secret' : 'notify_secret'));
        $header = $gateway === 'stripe' ? 'HTTP_STRIPE_SIGNATURE' : 'HTTP_TRIPLEA_SIGNATURE';
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', $header => 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$body, $secret)];
        $url = '/api/v1/webhooks/'.$gateway.'/'.$environment;
        $this->call('POST', $url, [], [], [], $server, $body)->assertOk();
        $this->call('POST', $url, [], [], [], $server, $body)->assertOk();
        $this->assertSame('paid', $this->order->fresh()->payment_status);
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertNotNull(WebhookEvent::first()->processed_at);
        $this->assertSame(1, $this->order->events()->where('type', 'paid')->count());
        $this->call('POST', $url, [], [], [], $server, $body.' ')->assertBadRequest();
        $this->call('POST', $url, [], [], [], array_replace($server, [$header => 't='.($time - 301).',v1='.hash_hmac('sha256', ($time - 301).'.'.$body, $secret)]), $body)->assertBadRequest();
        $this->postJson($url, $event)->assertBadRequest();
    }

    public function test_configuration_check_is_read_only_and_displays_no_secrets(): void
    {
        $this->artisan('store:payments-check', ['--gateway' => 'stripe'])->expectsOutputToContain('Configured')->assertSuccessful();
        config(['payments.stripe.sandbox.webhook_secret' => '']);
        $this->artisan('store:payments-check', ['--gateway' => 'stripe'])->expectsOutputToContain('Unavailable')->assertFailed();
    }

    public function test_unconfigured_payment_start_and_order_ownership_are_enforced(): void
    {
        [$this->order] = $this->checkout();
        config(['payments.stripe.sandbox.webhook_secret' => '']);
        $this->postJson('/api/v1/orders/'.$this->order->uuid.'/payments/stripe')->assertStatus(503);
        $this->assertDatabaseCount('payment_attempts', 0);
        config(['payments.stripe.sandbox.webhook_secret' => 'whsec_private_sandbox']);
        $this->withHeader('X-Order-Token', '')->postJson('/api/v1/orders/'.$this->order->uuid.'/payments/stripe')->assertNotFound();
        $this->assertDatabaseCount('payment_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_stripe_processing_is_pending_and_provider_refund_association_is_enforced(): void
    {
        $this->start();
        $this->status = 'processing';
        $this->postJson('/api/v1/orders/'.$this->order->uuid.'/payments/capture')->assertJsonPath('data.status', 'capture_pending');
        $this->assertNull($this->order->fresh()->paid_at);
        $this->status = 'paid';
        $this->postJson('/api/v1/orders/'.$this->order->uuid.'/payments/capture')->assertOk();
        $this->wrongRefundPayment = true;
        $this->actingAs($this->admin())->postJson('/api/v1/admin/orders/'.$this->order->uuid.'/refunds',
            ['request_key' => (string) Str::uuid(), 'amount_cents' => 1000, 'reason' => 'Wrong refund identity'])->assertJsonPath('data.status', 'unknown');
        $this->assertSame(0, $this->order->fresh()->refunded_cents);
    }

    public function test_valid_webhook_cannot_confirm_payment_without_provider_verification(): void
    {
        $this->start();
        $event = ['id' => 'evt_false_claim', 'type' => 'checkout.session.completed', 'livemode' => false,
            'data' => ['object' => ['id' => 'cs_sandbox', 'payment_status' => 'paid']]];
        $this->signedWebhook($event)->assertOk();
        $this->assertNull($this->order->fresh()->paid_at);
        $this->assertSame(20, ProductVariant::first()->stock);
    }

    public function test_webhook_recovers_an_ambiguous_payment_creation(): void
    {
        $this->timeout = true;
        [$data, $attempt] = $this->start('triple-a');
        $this->assertSame('unknown', $data['status']);
        $this->status = 'good';
        $this->signedWebhook(['event' => 'payment', 'payment_reference' => 'AAA-100-PMT', 'status' => 'good',
            'webhook_data' => ['attempt_uuid' => $attempt->uuid]])->assertOk();
        $this->assertSame('paid', $this->order->fresh()->payment_status);
        $this->assertSame('AAA-100-PMT', $attempt->fresh()->provider_order_id);
        $this->assertSame(1, $this->createCalls);
    }

    public function test_stripe_refund_webhook_recovers_original_unknown_refund_without_duplicate_rows(): void
    {
        $this->start();
        $this->status = 'paid';
        $this->postJson('/api/v1/orders/'.$this->order->uuid.'/payments/capture')->assertOk();
        $this->refundTimeout = true;
        $requestKey = (string) Str::uuid();
        $this->actingAs($this->admin())->postJson('/api/v1/admin/orders/'.$this->order->uuid.'/refunds',
            ['request_key' => $requestKey, 'amount_cents' => 1000, 'reason' => 'Lost refund response'])->assertJsonPath('data.status', 'unknown');
        $this->refundStatus = 'succeeded';
        $this->signedWebhook(['id' => 'evt_refund', 'type' => 'refund.updated', 'livemode' => false,
            'data' => ['object' => ['id' => 're_sandbox']]])->assertOk();
        $this->assertDatabaseCount('refunds', 1);
        $this->assertSame($requestKey, Refund::first()->request_key);
        $this->assertSame('completed', Refund::first()->status);
        $this->assertSame(1000, $this->order->fresh()->refunded_cents);
    }

    public function test_triple_a_uses_a_private_signing_secret_when_only_provider_keys_are_present(): void
    {
        config(['payments.triple-a.sandbox.notify_secret' => null]);
        $registry = app(GatewayRegistry::class);
        $this->assertTrue($registry->available('triple-a'));
        $secret = $registry->configuration('triple-a')['credentials']['notify_secret'];
        $this->assertSame(64, strlen($secret));
        $this->assertSame($secret, $registry->configuration('triple-a')['credentials']['notify_secret']);
        $this->assertNotSame($secret, $registry->configuration('triple-a', 'live')['credentials']['notify_secret']);
    }

    private function signedWebhook(array $event): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;
        $credentials = app(GatewayRegistry::class)->configuration($this->gateway, $this->environment)['credentials'];
        $secret = $credentials[$this->gateway === 'stripe' ? 'webhook_secret' : 'notify_secret'];
        $header = $this->gateway === 'stripe' ? 'HTTP_STRIPE_SIGNATURE' : 'HTTP_TRIPLEA_SIGNATURE';

        return $this->call('POST', '/api/v1/webhooks/'.$this->gateway.'/'.$this->environment, [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', $header => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret)], $body);
    }

    public function test_local_sandbox_setup_accepts_stripe_without_paypal_credentials(): void
    {
        config(['store.paypal.sandbox.client_secret' => null, 'payments.triple-a.enabled' => false]);
        $this->seed(LocalSandboxSeeder::class);
        $this->assertTrue(app(SettingService::class)->get('checkout_enabled'));
        $this->assertSame(['stripe'], array_column(app(GatewayRegistry::class)->methods(), 'slug'));
        $this->assertDatabaseHas('shipping_methods', ['name' => 'Sandbox Test Shipping', 'is_active' => true]);
    }
}
