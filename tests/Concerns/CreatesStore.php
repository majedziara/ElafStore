<?php

namespace Tests\Concerns;

use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\SettingService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\StoreSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

trait CreatesStore
{
    protected function prepareStore(): void
    {
        Notification::fake();
        $this->seed([StoreSeeder::class, DemoCatalogSeeder::class]);
        ShippingMethod::create(['name' => 'Test shipping', 'price_cents' => 500, 'is_active' => true]);
        app(SettingService::class)->put('checkout_enabled', true);
        app(SettingService::class)->put('tax_mode', 'none');
        config(['store.paypal.sandbox' => ['client_id' => 'test-client', 'client_secret' => 'test-secret', 'merchant_id' => 'TESTMERCHANT', 'webhook_id' => 'TESTWEBHOOK']]);
        PaymentGateway::where('slug', 'paypal')->update(['enabled' => true]);
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    protected function customer(): User
    {
        $user = User::factory()->create();
        $user->assignRole('customer');

        return $user;
    }

    protected function address(): array
    {
        return ['name' => 'Test Buyer', 'line1' => '123 Example Lane', 'line2' => null, 'city' => 'New York', 'state' => 'NY', 'postal_code' => '10001', 'country' => 'US', 'phone' => null];
    }

    protected function checkoutData(): array
    {
        return ['email' => 'buyer@example.com', 'shipping_address' => $this->address(), 'billing_address' => $this->address(), 'shipping_method_id' => ShippingMethod::first()->id, 'coupon_code' => null];
    }

    protected function cart(int $quantity = 1): string
    {
        $token = $this->postJson('/api/v1/cart')->assertCreated()->json('cart_token');
        $this->withHeader('X-Cart-Token', $token)->putJson('/api/v1/cart/items', ['variant_id' => ProductVariant::first()->id, 'quantity' => $quantity])->assertOk();

        return $token;
    }

    protected function checkout(int $quantity = 1): array
    {
        $this->cart($quantity);
        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/checkout', $this->checkoutData())->assertCreated();
        $order = Order::where('uuid', $response->json('data.uuid'))->firstOrFail();
        $this->withHeader('X-Order-Token', $response->json('order_token'));

        return [$order, $response->json('order_token')];
    }
}
