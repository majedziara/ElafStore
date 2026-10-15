<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\LinkService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesStore;
use Tests\TestCase;

class StoreApiTest extends TestCase
{
    use CreatesStore,RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareStore();
    }

    public function test_public_catalog_and_filters(): void
    {
        foreach (['/', '/up', '/sanctum/csrf-cookie', '/api/v1/products', '/api/v1/categories', '/api/v1/collections', '/api/v1/faqs', '/api/v1/settings', '/api/v1/payment-methods', '/api/v1/shipping-methods?state=NY'] as $url) {
            $this->getJson($url)->assertSuccessful();
        }
        $this->getJson('/api/v1/products/soft-rib-bralette')->assertOk()->assertJsonPath('data.name', 'Soft Rib Bralette');
        $this->getJson('/api/v1/products?category=clothing&sort=price_asc&in_stock=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/shipping-methods?state=XX')->assertUnprocessable();
        Product::where('slug', 'soft-rib-bralette')->update(['status' => 'draft']);
        $this->getJson('/api/v1/products/soft-rib-bralette')->assertNotFound();
    }

    public function test_registration_login_verification_profile_and_password(): void
    {
        $data = ['name' => 'Test Customer', 'email' => 'CUSTOMER@example.com', 'password' => 'ChangeMe123456!', 'password_confirmation' => 'ChangeMe123456!'];
        $this->postJson('/api/v1/auth/register', $data)->assertCreated()->assertJsonPath('data.email', 'customer@example.com');
        $user = User::where('email', 'customer@example.com')->firstOrFail();
        $this->getJson('/api/v1/auth/me')->assertOk();
        $token = app(LinkService::class)->issue('verify_email', $user->id, ['email' => $user->email], 60);
        $this->postJson('/api/v1/auth/consume/verify-email', ['token' => $token])->assertOk();
        $this->postJson('/api/v1/auth/consume/verify-email', ['token' => $token])->assertUnprocessable();
        $this->putJson('/api/v1/auth/profile', ['name' => 'Updated Name'])->assertOk()->assertJsonPath('data.name', 'Updated Name');
        $this->putJson('/api/v1/auth/password', ['current_password' => $data['password'], 'password' => 'NewPassword123456!', 'password_confirmation' => 'NewPassword123456!'])->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'NewPassword123456!'])->assertOk();
        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_admin_login_and_inactive_and_unverified_accounts_are_rejected(): void
    {
        $customer = $this->customer();
        $this->postJson('/api/v1/auth/admin-login', ['email' => $customer->email, 'password' => 'password'])->assertUnprocessable();
        $admin = $this->admin();
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($admin)->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $customer->forceFill(['is_active' => false])->save();
        app('auth')->forgetGuards();
        $this->actingAs($customer)->getJson('/api/v1/account/overview')->assertForbidden();
    }

    public function test_checkout_quote_idempotency_order_access_and_cancel_release_stock(): void
    {
        $this->cart(2);
        $data = $this->checkoutData();
        $this->postJson('/api/v1/checkout/quote', $data)->assertOk()->assertJsonPath('data.total_cents', 7300);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, ProductVariant::first()->reserved);
        $this->withHeader('Idempotency-Key', (string) Str::uuid());
        $first = $this->postJson('/api/v1/checkout', $data)->assertCreated();
        $this->postJson('/api/v1/checkout', $data)->assertCreated()->assertJsonPath('data.uuid', $first->json('data.uuid'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(2, ProductVariant::first()->reserved);
        $this->postJson('/api/v1/checkout', array_replace($data, ['email' => 'different@example.com']))->assertConflict();
        $uuid = $first->json('data.uuid');
        $this->getJson('/api/v1/orders/'.$uuid)->assertNotFound();
        $this->withHeader('X-Order-Token', $first->json('order_token'))->getJson('/api/v1/orders/'.$uuid)->assertOk();
        $this->getJson('/api/v1/orders/'.$uuid.'/receipt')->assertConflict();
        $this->postJson('/api/v1/orders/'.$uuid.'/cancel')->assertNoContent();
        $this->postJson('/api/v1/orders/'.$uuid.'/cancel')->assertNoContent();
        $this->assertSame(0, ProductVariant::first()->reserved);
        $this->assertSame('canceled', Order::first()->payment_status);
    }

    public function test_coupons_manual_tax_and_out_of_stock(): void
    {
        Coupon::create(['code' => 'WELCOME10', 'type' => 'percentage', 'value' => 1000, 'is_active' => true, 'usage_limit' => 1]);
        TaxRate::create(['state' => 'NY', 'basis_points' => 800, 'tax_shipping' => true]);
        app(SettingService::class)->put('tax_mode', 'manual');
        $this->cart();
        $data = array_replace($this->checkoutData(), ['coupon_code' => 'WELCOME10']);
        $this->postJson('/api/v1/checkout/quote', $data)->assertOk()->assertJsonPath('data.discount_cents', 340)->assertJsonPath('data.tax_cents', 285)->assertJsonPath('data.total_cents', 3845);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/checkout', $data)->assertCreated();
        $this->cart();
        $this->postJson('/api/v1/checkout/quote', $data)->assertUnprocessable();
        ProductVariant::first()->update(['stock' => 1]);
        $this->putJson('/api/v1/cart/items', ['variant_id' => ProductVariant::first()->id, 'quantity' => 2])->assertUnprocessable();
    }

    public function test_address_and_wishlist_ownership(): void
    {
        $customer = $this->customer();
        $other = $this->customer();
        app('auth')->forgetGuards();
        $this->actingAs($customer);
        $id = $this->postJson('/api/v1/account/addresses', $this->address() + ['is_default' => true])->assertOk()->json('data.id');
        $this->getJson('/api/v1/account/addresses')->assertOk()->assertJsonCount(1, 'data');
        $this->putJson('/api/v1/account/addresses/'.$id, array_replace($this->address(), ['city' => 'Brooklyn']))->assertOk();
        app('auth')->forgetGuards();
        $this->actingAs($other)->deleteJson('/api/v1/account/addresses/'.$id)->assertNotFound();
        $this->assertDatabaseHas('addresses', ['id' => $id]);
        app('auth')->forgetGuards();
        $this->actingAs($customer)->deleteJson('/api/v1/account/addresses/'.$id)->assertNoContent();
        $product = Product::first();
        $this->putJson('/api/v1/account/wishlist/'.$product->id)->assertNoContent();
        $this->getJson('/api/v1/account/wishlist')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson('/api/v1/account/wishlist/'.$product->id)->assertNoContent();
        $this->getJson('/api/v1/account/overview')->assertOk()->assertJsonPath('data.wishlist_count', 0);
        $this->getJson('/api/v1/account/orders')->assertOk();
    }

    #[DataProvider('resourceNames')]
    public function test_admin_resource_crud_using_postman_bodies(string $resource): void
    {
        $this->actingAs($this->admin());
        $collection = json_decode(file_get_contents(base_path('Nella-and-Vale.postman_collection.json')), true, 512, JSON_THROW_ON_ERROR);
        $variables = array_column($collection['variable'], 'value', 'key');
        $walk = function (array $items) use (&$walk, $resource): ?array {
            foreach ($items as $item) {
                if (isset($item['item'])) {
                    if ($found = $walk($item['item'])) {
                        return $found;
                    }
                } elseif ($item['request']['method'] === 'POST' && str_ends_with($item['request']['url']['raw'], '/resources/'.$resource)) {
                    return $item['request'];
                }
            }

            return null;
        };
        $request = $walk($collection['item']);
        $this->assertNotNull($request);
        $raw = preg_replace_callback('/\{\{([^}]+)\}\}/', fn ($m) => $variables[$m[1]] ?? '', $request['body']['raw']);
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if ($resource === 'categories') {
            $data['slug'] = 'postman-category';
        }
        $base = '/api/v1/admin/resources/'.$resource;
        $id = $this->postJson($base, $data)->assertOk()->json('data.id');
        $this->getJson($base)->assertOk();
        $this->getJson($base.'/'.$id)->assertOk();
        $this->putJson($base.'/'.$id, $data)->assertOk();
        $this->deleteJson($base.'/'.$id)->assertNoContent();
    }

    public static function resourceNames(): array
    {
        return array_map(fn ($name) => [$name], ['categories', 'collections', 'size-guides', 'shipping-methods', 'tax-rates', 'coupons', 'pages', 'faqs']);
    }

    public function test_admin_product_images_inventory_exports_and_settings(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin());
        $data = ['name' => 'Test Product', 'slug' => 'test-product', 'status' => 'published', 'returnable' => true, 'collection_ids' => [], 'variants' => [['sku' => 'TEST-M', 'color' => 'Ivory', 'size' => 'M', 'price_cents' => 3400, 'stock' => 10, 'is_active' => true]]];
        $response = $this->postJson('/api/v1/admin/products', $data)->assertOk();
        $id = $response->json('data.id');
        $variant = $response->json('data.variants.0.id');
        $data['variants'][0]['id'] = $variant;
        $this->putJson('/api/v1/admin/products/'.$id, $data)->assertOk();
        $this->getJson('/api/v1/admin/products/'.$id)->assertOk();
        $stock = ['request_key' => (string) Str::uuid(), 'delta' => 3, 'reason' => 'Test restock'];
        $this->postJson('/api/v1/admin/variants/'.$variant.'/stock', $stock)->assertOk()->assertJsonPath('data.stock', 13);
        $this->postJson('/api/v1/admin/variants/'.$variant.'/stock', $stock)->assertOk()->assertJsonPath('data.stock', 13);
        $this->postJson('/api/v1/admin/variants/'.$variant.'/stock', array_replace($stock, ['delta' => 4]))->assertConflict();
        $image = $this->postJson('/api/v1/admin/products/'.$id.'/images', ['image' => UploadedFile::fake()->image('photo.png')])->assertOk()->json('data.id');
        $this->putJson('/api/v1/admin/products/'.$id.'/images/'.$image, ['sort_order' => 1])->assertOk();
        $this->deleteJson('/api/v1/admin/products/'.$id.'/images/'.$image)->assertNoContent();
        foreach (['dashboard', 'products', 'exports/products', 'exports/orders', 'orders', 'returns', 'payments', 'webhooks', 'gateways', 'settings', 'customers', 'team', 'roles', 'audit-log', 'contact-messages'] as $path) {
            $this->getJson('/api/v1/admin/'.$path)->assertOk();
        }
        $this->putJson('/api/v1/admin/settings', ['brand_name' => 'Nella & Vale Test'])->assertOk();
        $this->putJson('/api/v1/admin/gateways/paypal', ['enabled' => true, 'environment' => 'sandbox'])->assertUnprocessable();
        $this->putJson('/api/v1/admin/gateways/stripe', ['enabled' => true])->assertUnprocessable();
        $this->postJson('/api/v1/admin/media', ['image' => UploadedFile::fake()->image('logo.png')])->assertOk();
        $this->deleteJson('/api/v1/admin/products/'.$id)->assertNoContent();
        $this->getJson('/api/v1/products/test-product')->assertNotFound();
    }

    public function test_guest_link_consumption_and_verified_order_claim(): void
    {
        [$order] = $this->checkout();
        $this->postJson('/api/v1/guest-orders/access-link', ['reference' => $order->reference, 'email' => $order->email])->assertOk();
        $raw = app(LinkService::class)->issue('guest_order', $order->id, [], 60);
        $response = $this->postJson('/api/v1/guest-orders/consume', ['token' => $raw])->assertOk();
        $this->withHeader('X-Order-Token', $response->json('data.order_token'));
        $user = $this->customer();
        $user->update(['email' => $order->email]);
        $this->actingAs($user)->postJson('/api/v1/account/orders/'.$order->uuid.'/claim')->assertOk();
        $this->assertSame($user->id, $order->fresh()->user_id);
        $this->postJson('/api/v1/guest-orders/consume', ['token' => $raw])->assertUnprocessable();
    }

    public function test_team_permissions_and_last_admin_protection(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $member = $this->postJson('/api/v1/admin/team', ['name' => 'Support', 'email' => 'support@example.com', 'is_active' => true, 'roles' => ['support']])->assertOk()->json('data.id');
        $this->putJson('/api/v1/admin/team/'.$member, ['name' => 'Support Updated', 'is_active' => false, 'roles' => ['support']])->assertOk();
        $this->putJson('/api/v1/admin/team/'.$admin->id, ['name' => $admin->name, 'is_active' => false, 'roles' => ['admin']])->assertUnprocessable();
        $role = Role::where('name', 'support')->firstOrFail();
        $this->putJson('/api/v1/admin/roles/'.$role->id.'/permissions', ['permissions' => ['dashboard.view']])->assertOk();
        $this->assertTrue($admin->fresh()->is_active);
    }
}
