<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ProductVariant;
use App\Notifications\StoreMail;
use App\Services\Payments\PayPalCallbackLinkService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesStore;
use Tests\TestCase;

class PayPalCallbackTest extends TestCase
{
    use CreatesStore,RefreshDatabase;

    private string $providerStatus = 'APPROVED';

    private ?string $captureStatus = null;

    private bool $captureTimeout = false;

    private bool $inspectUnavailable = false;

    private bool $amountMismatch = false;

    private ?Order $paymentOrder = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareStore();
        Http::preventStrayRequests();
        config(['store.url' => 'http://localhost:8000', 'store.paypal.return_url' => 'http://localhost:8000/api/paypal/return', 'store.paypal.cancel_url' => 'http://localhost:8000/api/paypal/cancel']);
    }

    private function startPayment(): array
    {
        [$order] = $this->checkout();
        $this->paymentOrder = clone $order;
        Http::fake(function (Request $r) {
            if (str_ends_with($r->url(), '/oauth2/token')) {
                return Http::response(['access_token' => 'test-token']);
            }
            if (str_ends_with($r->url(), '/v2/checkout/orders')) {
                return Http::response(['id' => 'PAYPALORDER1', 'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPALORDER1']]], 201);
            }
            if (str_ends_with($r->url(), '/capture')) {
                if ($this->captureTimeout) {
                    throw new ConnectionException('Test timeout');
                }$this->providerStatus = 'COMPLETED';
                $this->captureStatus = 'COMPLETED';
            }
            if ($this->inspectUnavailable) {
                return Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 503);
            }
            $amount = ['currency_code' => 'USD', 'value' => Money::decimal($this->paymentOrder->total_cents + ($this->amountMismatch ? 1 : 0))];
            $unit = ['custom_id' => $this->paymentOrder->uuid, 'amount' => $amount, 'payee' => ['merchant_id' => 'TESTMERCHANT']];
            if ($this->captureStatus) {
                $unit['payments'] = ['captures' => [['id' => 'CAPTURE1', 'status' => $this->captureStatus, 'amount' => $amount]]];
            }

            return Http::response(['id' => 'PAYPALORDER1', 'status' => $this->providerStatus, 'purchase_units' => [$unit]]);
        });
        $payment = $this->postJson('/api/v1/orders/'.$order->uuid.'/payments/paypal')->assertOk()->json('data');
        $this->flushHeaders();

        return [$order, $payment];
    }

    private function returnUrl(array $payment): string
    {
        return $payment['return_url'].'&token=PAYPALORDER1&PayerID=UNTRUSTED-PAYER';
    }

    public function test_paypal_receives_local_return_and_cancel_urls_with_protected_state(): void
    {
        [$order,$payment] = $this->startPayment();
        Http::assertSent(function (Request $r) use ($payment) {
            if (! str_ends_with($r->url(), '/v2/checkout/orders')) {
                return false;
            }
            $context = $r['payment_source']['paypal']['experience_context'];
            foreach (['return', 'cancel'] as $action) {
                $url = $context[$action.'_url'];
                if (! str_starts_with($url, 'http://localhost:8000/api/paypal/'.$action.'?state=')) {
                    return false;
                }
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                $state = json_decode(Crypt::decryptString($query['state']), true);
                if ($state['action'] !== $action || $state['attempt'] !== $payment['uuid']) {
                    return false;
                }
            }

return true;
        });
        $this->assertSame('PAYPALORDER1', $payment['provider_order_id']);
    }

    public function test_return_verifies_and_captures_without_an_authenticated_session(): void
    {
        [$order,$payment] = $this->startPayment();
        $response = $this->getJson($this->returnUrl($payment))->assertOk()->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.verification_pending', false)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(19, ProductVariant::first()->stock);
        $this->assertSame(0, ProductVariant::first()->reserved);
        $this->withHeader('X-Order-Token', $response->json('data.order_token'))->getJson('/api/v1/orders/'.$order->uuid)->assertOk();
        $this->flushHeaders();
        $this->getJson($this->returnUrl($payment))->assertOk();
        $this->assertDatabaseCount('inventory_movements', 1);
        $captures = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/capture'));
        $this->assertCount(1, $captures);
    }

    public function test_cancel_checks_provider_state_without_capturing_an_approved_order(): void
    {
        [$order,$payment] = $this->startPayment();
        $this->getJson($payment['cancel_url'].'&token=PAYPALORDER1')->assertStatus(202)->assertJsonPath('data.checkout_canceled', true)->assertJsonPath('data.payment_status', 'pending');
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/capture'));
        $this->assertNull($order->fresh()->paid_at);
        $this->assertNull($order->fresh()->canceled_at);
        $this->assertSame(1, ProductVariant::first()->reserved);
    }

    public function test_cancel_never_overwrites_an_already_completed_payment(): void
    {
        [$order,$payment] = $this->startPayment();
        $this->captureStatus = 'COMPLETED';
        $this->providerStatus = 'COMPLETED';
        $this->getJson($payment['cancel_url'])->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $this->assertSame(19, ProductVariant::first()->stock);
    }

    public function test_confirmed_void_releases_stock_from_the_cancel_callback(): void
    {
        [$order,$payment] = $this->startPayment();
        $this->providerStatus = 'VOIDED';
        $this->getJson($payment['cancel_url'])->assertOk()->assertJsonPath('data.payment_status', 'canceled');
        $this->assertSame(0, ProductVariant::first()->reserved);
    }

    public function test_tampered_state_wrong_order_and_wrong_callback_action_are_rejected(): void
    {
        [$order,$payment] = $this->startPayment();
        parse_str(parse_url($payment['return_url'], PHP_URL_QUERY), $query);
        $this->getJson('/api/paypal/return?state=invalid&token=PAYPALORDER1')->assertNotFound();
        $this->getJson($payment['return_url'].'&token=OTHERORDER')->assertNotFound();
        $this->getJson('/api/paypal/cancel?'.http_build_query($query))->assertNotFound();
        $this->getJson('/api/paypal/return?token=PAYPALORDER1')->assertUnprocessable();
        $this->assertNull($order->fresh()->paid_at);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/capture'));
    }

    public function test_expired_callback_state_is_rejected(): void
    {
        [$order,$payment] = $this->startPayment();
        $this->travel(121)->minutes();
        $this->getJson($this->returnUrl($payment))->assertStatus(410);
        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_client_claimed_success_cannot_replace_provider_confirmation(): void
    {
        [$order,$payment] = $this->startPayment();
        $this->providerStatus = 'CREATED';
        $this->getJson($this->returnUrl($payment).'&status=COMPLETED&paid=true&amount=0.01')->assertStatus(202)->assertJsonPath('data.payment_status', 'pending');
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame(20, ProductVariant::first()->stock);
    }

    public function test_amount_mismatch_is_rejected_before_capture(): void
    {
        [$order,$payment] = $this->startPayment();
        $this->amountMismatch = true;
        $this->getJson($this->returnUrl($payment))->assertConflict();
        $this->assertNull($order->fresh()->paid_at);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/capture'));
    }

    public function test_unknown_capture_returns_pending_and_keeps_the_reservation(): void
    {
        [$order,$payment] = $this->startPayment();
        $this->captureTimeout = true;
        $this->getJson($this->returnUrl($payment))->assertStatus(202)->assertJsonPath('data.attempt_status', 'unknown')->assertJsonPath('data.verification_pending', true);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame(1, ProductVariant::first()->reserved);
    }

    public function test_provider_unavailable_does_not_report_a_successful_payment(): void
    {
        [$order,$payment] = $this->startPayment();
        $this->inspectUnavailable = true;
        $this->getJson($this->returnUrl($payment))->assertStatus(202)->assertJsonPath('data.payment_status', 'pending')->assertJsonPath('data.verification_pending', true);
        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_order_emails_open_verified_order_links_on_this_application(): void
    {
        [$order,$payment] = $this->startPayment();
        $this->getJson($this->returnUrl($payment))->assertOk();
        $url = null;
        Notification::assertSentOnDemand(StoreMail::class, function (StoreMail $mail) use (&$url) {
            $url = $mail->url;

            return str_starts_with($url, 'http://localhost:8000/api/v1/guest-orders/consume?token=');
        });
        $this->getJson($url)->assertOk()->assertJsonPath('data.uuid', $order->uuid)->assertJsonPath('data.payment_status', 'paid');
        $this->getJson($url)->assertUnprocessable();
    }

    public function test_external_callback_configuration_is_rejected(): void
    {
        [$order,$payment] = $this->startPayment();
        config(['store.paypal.return_url' => 'https://emtedad-laravel-production.up.railway.app/api/paypal/return']);
        $this->expectException(HttpException::class);
        app(PayPalCallbackLinkService::class)->url('return',$payment['uuid']);
    }
}
