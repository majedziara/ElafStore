<?php

namespace Tests\Feature;

use App\Jobs\ProcessPayPalWebhook;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WebhookEvent;
use App\Services\Payments\PaymentService;
use App\Services\Payments\RefundService;
use App\Services\SettingService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesStore;
use Tests\TestCase;

class PaymentLifecycleTest extends TestCase
{
    use CreatesStore,RefreshDatabase;

    protected string $providerStatus = 'APPROVED';

    protected ?string $captureStatus = null;

    protected bool $captureTimeout = false;

    protected ?Order $paymentOrder = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareStore();
        Http::preventStrayRequests();
    }

    protected function fakePayPal(Order $order): void
    {
        $this->paymentOrder = clone $order;
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_ends_with($url, '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'test-token']);
            }
            if (str_ends_with($url, '/v1/notifications/verify-webhook-signature')) {
                return Http::response(['verification_status' => 'SUCCESS']);
            }
            if (str_ends_with($url, '/v2/checkout/orders')) {
                return Http::response(['id' => 'PAYPALORDER1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPALORDER1']]], 201);
            }
            if (str_ends_with($url, '/capture')) {
                if ($this->captureTimeout) {
                    throw new ConnectionException('Simulated timeout');
                }$this->captureStatus = 'COMPLETED';
                $this->providerStatus = 'COMPLETED';

                return Http::response($this->providerOrder());
            }
            if (str_ends_with($url, '/refund')) {
                return Http::response(['id' => 'PAYPALREFUND1', 'status' => 'COMPLETED', 'amount' => ['value' => Money::decimal($request['amount']['value'] ? Money::cents($request['amount']['value']) : 0), 'currency_code' => 'USD'], 'links' => [['rel' => 'up', 'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE1']]], 201);
            }
            if (str_contains($url, '/v2/payments/refunds/')) {
                return Http::response(['id' => 'PAYPALREFUND1', 'status' => 'COMPLETED', 'amount' => ['value' => '10.00', 'currency_code' => 'USD'], 'links' => [['rel' => 'up', 'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE1']]]);
            }
            if (str_contains($url, '/v2/checkout/orders/')) {
                return Http::response($this->providerOrder());
            }
            throw new \RuntimeException('Unexpected PayPal URL: '.$url);
        });
    }

    protected function providerOrder(): array
    {
        $unit = ['custom_id' => $this->paymentOrder->uuid, 'amount' => ['value' => Money::decimal($this->paymentOrder->total_cents), 'currency_code' => 'USD'], 'payee' => ['merchant_id' => 'TESTMERCHANT']];
        if ($this->captureStatus) {
            $unit['payments'] = ['captures' => [['id' => 'CAPTURE1', 'status' => $this->captureStatus, 'amount' => $unit['amount']]]];
        }

        return ['id' => 'PAYPALORDER1', 'status' => $this->providerStatus, 'purchase_units' => [$unit]];
    }

    protected function pay(Order $order): void
    {
        $this->fakePayPal($order);
        $this->postJson('/api/v1/orders/'.$order->uuid.'/payments/paypal')->assertOk()->assertJsonPath('data.status', 'awaiting_approval');
        $this->postJson('/api/v1/orders/'.$order->uuid.'/payments/capture')->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_paypal_creation_capture_receipt_and_inventory_are_idempotent(): void
    {
        [$order] = $this->checkout(2);
        $this->fakePayPal($order);
        $url = '/api/v1/orders/'.$order->uuid.'/payments';
        $first = $this->postJson($url.'/paypal')->assertOk();
        $this->postJson($url.'/paypal')->assertOk()->assertJsonPath('data.uuid', $first->json('data.uuid'));
        $this->assertDatabaseCount('payment_attempts', 1);
        Http::assertSentCount(2);
        $this->postJson($url.'/capture')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->postJson($url.'/capture')->assertOk();
        $this->assertSame(18, ProductVariant::first()->stock);
        $this->assertSame(0, ProductVariant::first()->reserved);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->getJson($url.'/status')->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $this->get('/api/v1/orders/'.$order->uuid.'/receipt')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->postJson('/api/v1/orders/'.$order->uuid.'/cancel')->assertConflict();
        $this->assertStringNotContainsString('test-secret', PaymentAttempt::first()->toJson());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v2/checkout/orders') && $r['purchase_units'][0]['amount']['value'] === '73.00' && $r['purchase_units'][0]['payee']['merchant_id'] === 'TESTMERCHANT');
    }

    public function test_ambiguous_capture_keeps_inventory_reserved_after_expiry(): void
    {
        [$order] = $this->checkout();
        $this->fakePayPal($order);
        $this->captureTimeout = true;
        $this->postJson('/api/v1/orders/'.$order->uuid.'/payments/paypal')->assertOk();
        $this->postJson('/api/v1/orders/'.$order->uuid.'/payments/capture')->assertOk()->assertJsonPath('data.status', 'unknown');
        $this->assertNotNull(PaymentAttempt::first()->capture_requested_at);
        $this->travel(31)->minutes();
        app(PaymentService::class)->sync(PaymentAttempt::first(), false);
        $this->assertSame('reserved', $order->fresh()->stock_status);
        $this->assertSame(1, ProductVariant::first()->reserved);
        $this->assertNull($order->fresh()->canceled_at);
    }

    public function test_unapproved_expired_payment_releases_inventory(): void
    {
        [$order] = $this->checkout();
        $this->fakePayPal($order);
        $this->providerStatus = 'PAYER_ACTION_REQUIRED';
        $this->postJson('/api/v1/orders/'.$order->uuid.'/payments/paypal')->assertOk();
        $this->travel(31)->minutes();
        app(PaymentService::class)->sync(PaymentAttempt::first());
        $this->assertSame('expired', $order->fresh()->payment_status);
        $this->assertSame(0, ProductVariant::first()->reserved);
    }

    public function test_provider_amount_or_merchant_mismatch_never_marks_order_paid(): void
    {
        [$order] = $this->checkout();
        $this->fakePayPal($order);
        $this->postJson('/api/v1/orders/'.$order->uuid.'/payments/paypal')->assertOk();
        $order->update(['total_cents' => 123]);
        $this->postJson('/api/v1/orders/'.$order->uuid.'/payments/capture')->assertConflict();
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame(20, ProductVariant::first()->stock);
    }

    public function test_refund_idempotency_limits_sync_and_representation_header(): void
    {
        [$order] = $this->checkout();
        $this->pay($order);
        $this->actingAs($this->admin());
        $url = '/api/v1/admin/orders/'.$order->uuid.'/refunds';
        $data = ['request_key' => (string) Str::uuid(), 'amount_cents' => 1000, 'reason' => 'Test refund'];
        $id = $this->postJson($url, $data)->assertOk()->assertJsonPath('data.status', 'completed')->json('data.id');
        $this->postJson($url, $data)->assertOk();
        $this->assertDatabaseCount('refunds', 1);
        $this->assertSame(1000, $order->fresh()->refunded_cents);
        $this->postJson($url, array_replace($data, ['amount_cents' => 1100]))->assertConflict();
        $this->postJson($url, ['request_key' => (string) Str::uuid(), 'amount_cents' => 4000, 'reason' => 'Too much'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/refunds/'.$id.'/sync')->assertOk();
        $this->assertSame(1000, $order->fresh()->refunded_cents);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/refund') && $r->hasHeader('Prefer', 'return=representation'));
    }

    public function test_signed_webhook_is_deduplicated_and_invalid_headers_are_rejected(): void
    {
        Queue::fake();
        [$order] = $this->checkout();
        $this->fakePayPal($order);
        $this->postJson('/api/v1/orders/'.$order->uuid.'/payments/paypal')->assertOk();
        $event = ['id' => 'EVENT1', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'PAYPALORDER1']];
        $this->postJson('/api/v1/webhooks/paypal/sandbox', $event)->assertBadRequest();
        $headers = ['PAYPAL-TRANSMISSION-ID' => 'T1', 'PAYPAL-TRANSMISSION-TIME' => now()->toIso8601String(), 'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/cert', 'PAYPAL-AUTH-ALGO' => 'SHA256withRSA', 'PAYPAL-TRANSMISSION-SIG' => 'signature'];
        $this->withHeaders($headers)->postJson('/api/v1/webhooks/paypal/sandbox', $event)->assertOk();
        $row = WebhookEvent::firstOrFail();
        (new ProcessPayPalWebhook($row->id))->handle(app(PaymentService::class), app(RefundService::class));
        $this->postJson('/api/v1/webhooks/paypal/sandbox', $event)->assertOk();
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertSame('paid', $order->fresh()->payment_status);
        Queue::assertPushed(ProcessPayPalWebhook::class, 1);
        $this->withHeader('PAYPAL-CERT-URL', 'https://evil.example/cert')->postJson('/api/v1/webhooks/paypal/sandbox', $event)->assertBadRequest();
    }

    public function test_shipping_returns_private_photos_refund_and_restock(): void
    {
        Storage::fake('local');
        Product::first()->update(['returnable' => true]);
        app(SettingService::class)->put('returns_enabled', true);
        app(SettingService::class)->put('return_days', 30);
        [$order] = $this->checkout();
        $this->pay($order);
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/process')->assertNoContent();
        $shipment = $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/shipments', ['carrier' => 'Test', 'tracking_number' => 'TEST123'])->assertOk()->json('data.id');
        $this->postJson('/api/v1/admin/shipments/'.$shipment.'/delivered')->assertNoContent();
        $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/notes', ['message' => 'Private internal note'])->assertOk();
        $this->getJson('/api/v1/orders/'.$order->uuid)->assertOk()->assertJsonMissing(['message' => 'Private internal note']);
        $return = $this->postJson('/api/v1/orders/'.$order->uuid.'/returns', ['type' => 'return', 'items' => [['order_item_id' => $order->items()->first()->id, 'quantity' => 1, 'reason' => 'Fit issue']]])->assertCreated();
        $uuid = $return->json('data.uuid');
        $id = $return->json('data.id');
        $this->postJson('/api/v1/returns/'.$uuid.'/photos', ['photos' => [UploadedFile::fake()->image('return.png')]])->assertOk();
        $this->get('/api/v1/returns/'.$uuid.'/photos/0')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/admin/returns/'.$uuid)->assertOk();
        $this->get('/api/v1/admin/returns/'.$uuid.'/photos/0')->assertOk();
        $this->putJson('/api/v1/admin/returns/'.$uuid, ['status' => 'approved', 'admin_note' => 'INTERNAL'])->assertOk();
        $this->getJson('/api/v1/returns/'.$uuid)->assertOk()->assertJsonMissing(['admin_note' => 'INTERNAL']);
        $this->putJson('/api/v1/admin/returns/'.$uuid, ['status' => 'received'])->assertOk();
        $this->putJson('/api/v1/admin/returns/'.$uuid, ['status' => 'completed'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/refunds', ['request_key' => (string) Str::uuid(), 'amount_cents' => 1000, 'reason' => 'Return refund', 'return_request_id' => $id])->assertOk();
        $this->putJson('/api/v1/admin/returns/'.$uuid, ['status' => 'completed'])->assertOk();
        $this->postJson('/api/v1/admin/returns/'.$uuid.'/restock')->assertNoContent();
        $this->postJson('/api/v1/admin/returns/'.$uuid.'/restock')->assertUnprocessable();
        $this->assertSame(20, ProductVariant::first()->stock);
    }

    public function test_size_exchange_reserves_replacement_and_deducts_only_on_shipment(): void
    {
        Product::first()->update(['returnable' => true]);
        app(SettingService::class)->put('returns_enabled', true);
        app(SettingService::class)->put('exchanges_enabled', true);
        app(SettingService::class)->put('return_days', 30);
        [$order] = $this->checkout();
        $this->pay($order);
        $admin = $this->admin();
        $this->actingAs($admin);
        $shipment = $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/shipments', ['carrier' => 'Test', 'tracking_number' => 'ORIGINAL1'])->assertOk()->json('data.id');
        $this->postJson('/api/v1/admin/shipments/'.$shipment.'/delivered')->assertNoContent();
        $replacement = ProductVariant::where('product_id', Product::first()->id)->where('id', '!=', ProductVariant::first()->id)->firstOrFail();
        $response = $this->postJson('/api/v1/orders/'.$order->uuid.'/returns', ['type' => 'exchange', 'items' => [['order_item_id' => $order->items()->first()->id, 'quantity' => 1, 'reason' => 'Need another size', 'replacement_variant_id' => $replacement->id]]])->assertCreated();
        $uuid = $response->json('data.uuid');
        $id = $response->json('data.id');
        $this->assertSame(0, $replacement->fresh()->reserved);
        $this->putJson('/api/v1/admin/returns/'.$uuid, ['status' => 'approved'])->assertOk();
        $this->assertSame(1, $replacement->fresh()->reserved);
        $this->putJson('/api/v1/admin/returns/'.$uuid, ['status' => 'received'])->assertOk();
        $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/shipments', ['carrier' => 'Test', 'tracking_number' => 'REPLACEMENT1', 'return_request_id' => $id])->assertOk();
        $this->assertSame(19, $replacement->fresh()->stock);
        $this->assertSame(0, $replacement->fresh()->reserved);
        $this->putJson('/api/v1/admin/returns/'.$uuid, ['status' => 'completed'])->assertOk();
        $this->postJson('/api/v1/admin/orders/'.$order->uuid.'/shipments', ['carrier' => 'Test', 'tracking_number' => 'DUPLICATE', 'return_request_id' => $id])->assertUnprocessable();
        $this->assertSame(19, $replacement->fresh()->stock);
    }

    public function test_fully_refunded_orders_are_removed_from_the_fulfillment_dashboard(): void
    {
        [$order] = $this->checkout();
        $this->pay($order);
        $this->actingAs($this->admin());
        $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.awaiting_fulfillment', 1);
        $this->captureStatus = 'REFUNDED';
        $this->postJson('/api/v1/orders/'.$order->uuid.'/payments/capture')->assertOk();
        $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.awaiting_fulfillment', 0);
        $this->assertSame('refunded', $order->fresh()->payment_status);
    }

    public function test_unknown_create_request_is_never_duplicated(): void
    {
        [$order] = $this->checkout();
        Http::fake(['*/oauth2/token' => Http::response(['access_token' => 'test-token']), '*/v2/checkout/orders' => Http::failedConnection()]);
        $url = '/api/v1/orders/'.$order->uuid.'/payments/paypal';
        $this->postJson($url)->assertOk()->assertJsonPath('data.status', 'unknown');
        $this->postJson($url)->assertOk()->assertJsonPath('data.status', 'unknown');
        $this->assertDatabaseCount('payment_attempts', 1);
        Http::assertSentCount(2);
    }
}
