<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\StoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StatefulAdminSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(StoreSeeder::class);
        config([
            'sanctum.stateful' => $this->configurationWithEnvironment('sanctum', 'SANCTUM_STATEFUL_DOMAINS', 'https://elaf-store.vercel.app/')['stateful'],
            'cors.allowed_origins' => $this->configurationWithEnvironment('cors', 'FRONTEND_ORIGINS', 'https://elaf-store.vercel.app/')['allowed_origins'],
            'session.driver' => 'database',
            'session.secure' => false,
        ]);
        $this->app['env'] = 'local';
        $this->withCredentials()->withHeaders([
            'Origin' => 'https://elaf-store.vercel.app',
            'Referer' => 'https://elaf-store.vercel.app/',
        ]);
    }

    public function test_admin_login_session_authenticates_admin_pages_with_a_full_url_in_configuration(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->loginWithCookies($admin, '/api/v1/auth/admin-login');

        $this->freshRequest()->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $admin->id);

        foreach (['dashboard', 'products', 'orders', 'returns', 'payments', 'webhooks', 'gateways', 'settings', 'customers', 'contact-messages', 'team', 'roles', 'audit-log', 'resources/categories', 'resources/collections', 'resources/coupons', 'resources/pages', 'resources/faqs', 'resources/shipping-methods'] as $path) {
            $this->freshRequest()->getJson('/api/v1/admin/'.$path)->assertOk();
        }
    }

    public function test_admin_session_requires_a_cookie_and_a_trusted_frontend(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->loginWithCookies($admin, '/api/v1/auth/admin-login');
        $this->freshRequest()->getJson('/api/v1/admin/dashboard')->assertOk();

        $this->withHeaders(['Origin' => 'https://untrusted.example', 'Referer' => 'https://untrusted.example/']);
        $this->freshRequest()->getJson('/api/v1/admin/dashboard')->assertUnauthorized();

        $this->withHeaders(['Origin' => 'https://elaf-store.vercel.app', 'Referer' => 'https://elaf-store.vercel.app/']);
        $this->unencryptedCookies = [];
        $this->freshRequest()->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    }

    public function test_customer_session_still_requires_admin_permissions(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $this->loginWithCookies($customer, '/api/v1/auth/login');

        $this->freshRequest()->getJson('/api/v1/account/overview')->assertOk();
        $this->freshRequest()->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_admin_writes_still_require_the_session_csrf_token(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->loginWithCookies($admin, '/api/v1/auth/admin-login');

        $this->withoutHeader('X-XSRF-TOKEN');
        $this->freshRequest()->putJson('/api/v1/admin/settings', [])->assertStatus(419);
    }

    public function test_cors_accepts_only_configured_origins_after_trimming_trailing_slashes(): void
    {
        config(['cors.allowed_origins' => $this->configurationWithEnvironment('cors', 'FRONTEND_ORIGINS',
            ' https://elaf-store.vercel.app/, http://localhost:3000/, https://elaf-store.vercel.app/, '
        )['allowed_origins']]);

        $this->getJson('/sanctum/csrf-cookie')->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://elaf-store.vercel.app')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        $this->withHeader('Origin', 'https://untrusted.example');
        $this->freshRequest()->getJson('/sanctum/csrf-cookie')->assertNoContent()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_stateful_configuration_preserves_explicit_hosts_ports_wildcards_and_request_host_placeholder(): void
    {
        config(['sanctum.stateful' => $this->configurationWithEnvironment('sanctum', 'SANCTUM_STATEFUL_DOMAINS',
            ' https://store.example:8443/, http://localhost:3000/, *.preview.example, ::1, '.Sanctum::$currentRequestHostPlaceholder.', , store.example:8443 '
        )['stateful']]);

        foreach (['https://store.example:8443/', 'http://localhost:3000/', 'https://branch.preview.example/', 'https://localhost/'] as $referer) {
            $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend(Request::create('/', server: ['HTTP_REFERER' => $referer])), $referer);
        }

        foreach (['https://store.example/', 'http://localhost:3001/', 'https://store.example.evil.test/', 'https://untrusted.example/'] as $referer) {
            $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend(Request::create('/', server: ['HTTP_REFERER' => $referer])), $referer);
        }
    }

    private function loginWithCookies(User $user, string $path): void
    {
        $this->rememberResponseCookies($this->getJson('/sanctum/csrf-cookie')->assertNoContent());
        $this->freshRequest();
        $response = $this->postJson($path, ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.id', $user->id);
        $this->rememberResponseCookies($response);
    }

    private function rememberResponseCookies(TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());

            if ($cookie->getName() === 'XSRF-TOKEN') {
                $this->withHeader('X-XSRF-TOKEN', $cookie->getValue());
            }
        }
    }

    private function freshRequest(): static
    {
        config(['auth.defaults.guard' => 'web']);
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    private function configurationWithEnvironment(string $name, string $key, string $value): array
    {
        $previousEnv = $_ENV[$key] ?? null;
        $previousServer = $_SERVER[$key] ?? null;
        $_ENV[$key] = $_SERVER[$key] = $value;

        try {
            return require config_path($name.'.php');
        } finally {
            if ($previousEnv === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $previousEnv;
            }

            if ($previousServer === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $previousServer;
            }
        }
    }
}
