<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesStore;
use Tests\TestCase;

class FrontendIntegrationTest extends TestCase
{
    use CreatesStore, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareStore();
        config(['store.bff_rate_secret' => str_repeat('a', 64)]);
    }

    public function test_public_settings_include_return_policy_flags_without_server_secrets(): void
    {
        $settings = app(SettingService::class);
        $settings->put('returns_enabled', true);
        $settings->put('exchanges_enabled', false);
        $settings->put('return_days', 30);
        $settings->put('bff_rate_secret', 'PRIVATE-BFF-SECRET');
        $settings->put('paypal_client_secret', 'PRIVATE-PAYPAL-SECRET');

        $this->getJson('/api/v1/settings')->assertOk()
            ->assertJsonPath('data.returns_enabled', true)
            ->assertJsonPath('data.exchanges_enabled', false)
            ->assertJsonPath('data.return_days', 30)
            ->assertJsonMissingPath('data.bff_rate_secret')
            ->assertJsonMissingPath('data.paypal_client_secret');
    }

    public function test_catalog_ids_filter_returns_only_requested_published_products(): void
    {
        $products = Product::orderBy('id')->get();
        $published = $products[0];
        $draft = $products[1];
        $draft->update(['status' => 'draft']);

        $this->getJson('/api/v1/products?'.http_build_query(['ids' => [$published->id, $draft->id]]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $published->id);
        $this->getJson('/api/v1/products?ids[0]=999999')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_product_cart_and_order_images_use_the_public_storage_origin(): void
    {
        config(['app.url' => 'http://localhost:3000', 'filesystems.disks.public.url' => 'http://127.0.0.1:8000/storage']);
        Storage::fake('public', ['url' => config('filesystems.disks.public.url')]);
        $product = Product::first();
        $product->images()->create(['path' => 'products/test-photo.png', 'alt' => 'Test product', 'sort_order' => 0]);
        $imageUrl = 'http://127.0.0.1:8000/storage/products/test-photo.png';

        $this->getJson('/api/v1/products/'.$product->slug)->assertOk()->assertJsonPath('data.images.0.url', $imageUrl);
        [$order] = $this->checkout();
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items.0.image_url', $imageUrl);
        $this->getJson('/api/v1/orders/'.$order->uuid)->assertOk()->assertJsonPath('data.items.0.image_url', $imageUrl);
    }

    #[DataProvider('invalidProductIds')]
    public function test_catalog_rejects_invalid_ids_filters(mixed $ids): void
    {
        $this->getJson('/api/v1/products?'.http_build_query(['ids' => $ids]))->assertUnprocessable();
    }

    public static function invalidProductIds(): array
    {
        return [
            'scalar' => ['1'],
            'too many ids' => [range(1, 31)],
            'duplicate ids' => [[1, 1]],
            'zero' => [[0]],
            'negative id' => [[-1]],
            'non integer' => [['invalid']],
        ];
    }

    public function test_return_photo_count_is_visible_while_private_paths_stay_hidden(): void
    {
        Storage::fake('local');
        Product::first()->update(['returnable' => true]);
        app(SettingService::class)->put('returns_enabled', true);
        app(SettingService::class)->put('return_days', 30);
        [$order] = $this->checkout();
        $order->update(['payment_status' => 'paid', 'paid_at' => now(), 'delivered_at' => now()]);

        $return = $this->postJson('/api/v1/orders/'.$order->uuid.'/returns', [
            'type' => 'return',
            'items' => [['order_item_id' => $order->items()->first()->id, 'quantity' => 1, 'reason' => 'Fit issue']],
        ])->assertCreated()->assertJsonPath('data.photo_count', 0)->assertJsonMissingPath('data.photo_paths');
        $uuid = $return->json('data.uuid');
        $this->postJson('/api/v1/returns/'.$uuid.'/photos', ['photos' => [UploadedFile::fake()->image('first.png'), UploadedFile::fake()->image('second.png')]])
            ->assertOk()->assertJsonPath('data.photo_count', 2);
        $this->getJson('/api/v1/returns/'.$uuid)->assertOk()->assertJsonPath('data.photo_count', 2)->assertJsonMissingPath('data.photo_paths');
        $this->getJson('/api/v1/orders/'.$order->uuid.'/returns')->assertOk()->assertJsonPath('data.0.photo_count', 2)->assertJsonMissingPath('data.0.photo_paths');
    }

    public function test_signed_bff_clients_have_independent_public_write_limits(): void
    {
        foreach (['198.51.100.10', '2001:db8::10'] as $ip) {
            $this->withHeaders($this->signedHeaders($ip));
            for ($attempt = 0; $attempt < 12; $attempt++) {
                $this->postJson('/api/v1/cart')->assertCreated();
            }
        }
    }

    public function test_forged_bff_headers_cannot_bypass_public_write_limits(): void
    {
        for ($attempt = 0; $attempt < 21; $attempt++) {
            $headers = $this->signedHeaders('198.51.100.'.($attempt + 1));
            $headers['X-NV-Client-Signature'] = str_repeat('b', 64);
            $response = $this->withHeaders($headers)->postJson('/api/v1/cart');
            if ($attempt < 20) {
                $response->assertCreated();
            } else {
                $response->assertTooManyRequests();
            }
        }
    }

    public function test_every_limiter_uses_a_signed_client_and_rejects_expired_or_invalid_headers(): void
    {
        $valid = $this->signedHeaders('198.51.100.10');
        $expired = $this->signedHeaders('198.51.100.10', now()->subSeconds(61)->timestamp);
        $future = $this->signedHeaders('198.51.100.10', now()->addSeconds(61)->timestamp);
        $invalidIp = $this->signedHeaders('invalid');
        $invalidTime = $this->signedHeaders('198.51.100.10');
        $invalidTime['X-NV-Client-Time'] = 'invalid';

        foreach (['auth', 'links', 'public-write', 'payments'] as $name) {
            foreach ([$valid, $expired, $future, $invalidIp, $invalidTime, []] as $headers) {
                $request = Request::create('/', 'POST', ['email' => 'buyer@example.com'], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
                $request->headers->add($headers);
                $result = RateLimiter::limiter($name)($request);
                $limit = is_array($result) ? $result[0] : $result;
                $this->assertSame($headers === $valid ? '198.51.100.10' : '127.0.0.1', $limit->key);
            }
        }

        config(['store.bff_rate_secret' => null]);
        $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $request->headers->add($valid);
        $this->assertSame('127.0.0.1', RateLimiter::limiter('public-write')($request)->key);
    }

    /**
     * @return array<string, string>
     */
    private function signedHeaders(string $ip, ?int $timestamp = null): array
    {
        $time = (string) ($timestamp ?? now()->timestamp);

        return [
            'X-NV-Client-IP' => $ip,
            'X-NV-Client-Time' => $time,
            'X-NV-Client-Signature' => hash_hmac('sha256', $ip.'|'.$time, config('store.bff_rate_secret')),
        ];
    }
}
