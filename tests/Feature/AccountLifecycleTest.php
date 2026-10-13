<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\User;
use App\Notifications\StoreMail;
use App\Services\AuthService;
use App\Services\LinkService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesStore;
use Tests\TestCase;

class AccountLifecycleTest extends TestCase
{
    use CreatesStore,RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareStore();
    }

    public function test_email_change_requires_confirmation_and_revokes_previous_sessions(): void
    {
        $user = $this->customer();
        $old = $user->email;
        $this->actingAs($user)->postJson('/api/v1/auth/change-email', ['email' => 'new@example.com', 'current_password' => 'password'])->assertOk();
        $this->assertSame($old, $user->fresh()->email);
        $this->assertSame('new@example.com', $user->fresh()->pending_email);
        $token = app(LinkService::class)->issue('change_email', $user->id, ['old_email' => $old, 'email' => 'new@example.com'], 60);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->postJson('/api/v1/auth/consume/change-email', ['token' => $token])->assertOk();
        $this->assertSame('new@example.com', $user->fresh()->email);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->postJson('/api/v1/auth/consume/change-email', ['token' => $token])->assertUnprocessable();
    }

    public function test_password_reset_sends_generic_response_and_revokes_sessions_and_api_tokens(): void
    {
        $user = $this->customer();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        Notification::assertSentTo($user, ResetPassword::class);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.com'])->assertOk();
        $token = Password::createToken($user);
        $user->createToken('old-token');
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'NewPassword123456!', 'password_confirmation' => 'NewPassword123456!'])->assertOk();
        $this->assertTrue(Hash::check('NewPassword123456!', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_verification_expired_link_and_resend(): void
    {
        $user = User::factory()->unverified()->create();
        $user->assignRole('customer');
        $this->actingAs($user)->postJson('/api/v1/auth/resend-verification')->assertOk();
        Notification::assertSentTo($user, StoreMail::class);
        $raw = app(LinkService::class)->issue('verify_email', $user->id, ['email' => $user->email], 1);
        $this->travel(2)->minutes();
        $this->postJson('/api/v1/auth/consume/verify-email', ['token' => $raw])->assertUnprocessable();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_contact_messages_customer_details_and_permissions(): void
    {
        $this->postJson('/api/v1/contact', ['name' => 'Customer', 'email' => 'buyer@example.com', 'subject' => 'Order question', 'message' => 'Please help with my order.'])->assertCreated();
        $message = ContactMessage::firstOrFail();
        $customer = $this->customer();
        $this->actingAs($this->admin());
        $this->getJson('/api/v1/admin/contact-messages/'.$message->id)->assertOk();
        $this->putJson('/api/v1/admin/contact-messages/'.$message->id, ['status' => 'closed', 'admin_note' => 'Resolved'])->assertOk();
        $this->getJson('/api/v1/admin/customers/'.$customer->id)->assertOk();
        app('auth')->forgetGuards();
        $this->actingAs($customer)->getJson('/api/v1/admin/contact-messages')->assertForbidden();
    }

    public function test_cart_token_and_checkout_are_bound_to_the_authenticated_customer(): void
    {
        $customer = $this->customer();
        $other = $this->customer();
        $this->actingAs($customer);
        $this->cart();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/checkout', $this->checkoutData())->assertCreated()->assertJsonPath('data.email', $customer->email)->assertJsonPath('order_token', null);
        app('auth')->forgetGuards();
        $this->actingAs($other)->getJson('/api/v1/cart')->assertNotFound();
    }

    public function test_verification_email_opens_a_local_get_endpoint(): void
    {
        $user = User::factory()->unverified()->create();
        app(AuthService::class)->sendVerification($user);
        $url = null;
        Notification::assertSentTo($user, StoreMail::class, function (StoreMail $mail) use (&$url) {
            $url = $mail->url;

            return str_starts_with($url, config('store.url').'/api/v1/auth/consume/verify-email?token=');
        });
        $this->getJson($url)->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_email_change_is_confirmed_inside_this_project(): void
    {
        $user = $this->customer();
        $this->actingAs($user)->postJson('/api/v1/auth/change-email', ['email' => 'changed@example.com', 'current_password' => 'password'])->assertOk();
        $url = null;
        Notification::assertSentOnDemand(StoreMail::class, function (StoreMail $mail) use (&$url) {
            $url = $mail->url;

            return str_starts_with($url, config('store.url').'/api/v1/auth/consume/change-email?token=');
        });
        $this->getJson($url)->assertOk();
        $this->assertSame('changed@example.com', $user->fresh()->email);
    }

    public function test_reset_password_page_and_submission_stay_on_this_application(): void
    {
        $user = $this->customer();
        $token = Password::createToken($user);
        $notification = new ResetPassword($token);
        $url = $notification->toMail($user)->actionUrl;
        $this->assertStringStartsWith(config('store.url').'/api/v1/auth/reset-password?', $url);
        $this->get($url)->assertOk()->assertSee('Reset your password')->assertSee(config('store.url').'/api/v1/auth/reset-password', false)->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'LocalReset123456!', 'password_confirmation' => 'LocalReset123456!'])->assertOk();
        $this->assertTrue(Hash::check('LocalReset123456!', $user->fresh()->password));
        $this->getJson($url)->assertUnprocessable();
    }
}
