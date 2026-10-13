<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\StoreMail;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AuthService
{
    public function __construct(private LinkService $links) {}

    public function register(array $data, Request $request): User
    {
        $user = DB::transaction(function () use ($data) {
            $user = User::create($data);
            $user->assignRole('customer');
            $this->sendVerification($user);

            return $user;
        });
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $user;
    }

    public function login(array $data, Request $request, bool $admin = false): User
    {
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! $user->is_active || ! Hash::check($data['password'], $user->password) ||
            ($admin && (! $user->can('dashboard.view') || ! $user->email_verified_at))) {
            throw ValidationException::withMessages(['email' => 'The provided credentials are incorrect.']);
        }
        Auth::guard('web')->login($user, (bool) ($data['remember'] ?? false));
        $request->session()->regenerate();

        return $user;
    }

    public function sendVerification(User $user): void
    {
        if ($user->email_verified_at) {
            return;
        }
        $raw = $this->links->issue('verify_email', $user->id, ['email' => $user->email], config('store.link_minutes'));
        $user->notify(new StoreMail('Verify your Nella & Vale email', ['Confirm your email address to finish setting up your account.'],
            config('store.url').'/api/v1/auth/consume/verify-email?token='.$raw, 'Verify email'));
    }

    public function verify(string $token): User
    {
        return $this->links->consume($token, 'verify_email', function ($link) {
            $user = User::whereKey($link->target_id)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active && $user->email === $link->payload['email'], 422, 'Email no longer matches.');
            if (! $user->email_verified_at) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            return $user;
        });
    }

    public function requestEmailChange(User $user, array $data): void
    {
        abort_unless(Hash::check($data['current_password'], $user->password), 422, 'Current password is incorrect.');
        abort_if($data['email'] === $user->email, 422, 'Choose a different email.');
        DB::transaction(function () use ($user, $data) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->update(['pending_email' => $data['email']]);
            $raw = $this->links->issue('change_email', $user->id, ['old_email' => $locked->email, 'email' => $data['email']], config('store.link_minutes'));
            Notification::route('mail', $data['email'])->notify(new StoreMail('Confirm your new email address',
                ['Your account email will change only after you confirm this link.'],
                config('store.url').'/api/v1/auth/consume/change-email?token='.$raw, 'Confirm email change'));
        });
    }

    public function confirmEmailChange(string $token): void
    {
        $this->links->consume($token, 'change_email', function ($link) {
            $user = User::whereKey($link->target_id)->lockForUpdate()->firstOrFail();
            $email = $link->payload['email'];
            abort_unless($user->is_active && $user->email === $link->payload['old_email'] && $user->pending_email === $email, 422, 'Request no longer valid.');
            abort_if(User::where('email', $email)->whereKeyNot($user->id)->exists(), 422, 'Email is already in use.');
            $old = $user->email;
            $user->forceFill(['email' => $email, 'pending_email' => null, 'email_verified_at' => now(), 'remember_token' => Str::random(60)])->save();
            $this->revokeSessions($user);
            Notification::route('mail', $old)->notify(new StoreMail('Your Nella & Vale email changed', ['Your account email address has been changed. Contact support if you did not request this.']));
        });
    }

    public function changePassword(User $user, array $data, Request $request): void
    {
        abort_unless(Hash::check($data['current_password'], $user->password), 422, 'Current password is incorrect.');
        $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
        $this->revokeSessions($user, $request->session()->getId());
        $request->session()->regenerate();
    }

    public function reset(array $data): void
    {
        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $this->revokeSessions($user);
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'The reset link is invalid or expired.']);
        }
    }

    public function revokeSessions(User $user, ?string $except = null): void
    {
        $query = DB::table('sessions')->where('user_id', $user->id);
        if ($except) {
            $query->where('id', '!=', $except);
        }
        $query->delete();
        $user->tokens()->delete();
    }
}
