<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuthRequest;
use App\Jobs\SendPasswordResetLink;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    public function __construct(private AuthService $service) {}

    private function user($user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'pending_email' => $user->pending_email,
            'email_verified' => (bool) $user->email_verified_at, 'marketing_opt_in' => $user->marketing_opt_in, 'permissions' => $user->getAllPermissions()->pluck('name')];
    }

    public function register(AuthRequest $r)
    {
        return response()->json(['data' => $this->user($this->service->register($r->validated(), $r))], 201);
    }

    public function login(AuthRequest $r)
    {
        return ['data' => $this->user($this->service->login($r->validated(), $r))];
    }

    public function adminLogin(AuthRequest $r)
    {
        return ['data' => $this->user($this->service->login($r->validated(), $r, true))];
    }

    public function me(Request $r)
    {
        return ['data' => $this->user($r->user())];
    }

    public function logout(Request $r)
    {
        Auth::guard('web')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return response()->noContent();
    }

    public function forgot(AuthRequest $r)
    {
        SendPasswordResetLink::dispatch($r->validated('email'));

        return ['message' => 'If the account exists, a reset link has been sent.'];
    }

    public function reset(AuthRequest $r)
    {
        $this->service->reset($r->validated());

        return ['message' => 'Password reset. Please sign in.'];
    }

    public function resetForm(Request $request): Response
    {
        $data = $request->validate(['email' => 'required|email:rfc|max:254', 'token' => 'required|string|max:255']);
        $user = User::where('email', $data['email'])->first();
        abort_unless($user && Password::broker()->tokenExists($user, $data['token']), 422, 'The reset link is invalid or expired.');

        return response()->view('auth.reset-password', $data + ['action' => config('store.url').'/api/v1/auth/reset-password'])
            ->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function resend(Request $r)
    {
        $this->service->sendVerification($r->user());

        return ['message' => 'Verification email requested.'];
    }

    public function consume(AuthRequest $r)
    {
        if ($r->route('purpose') === 'change-email') {
            $this->service->confirmEmailChange($r->validated('token'));

            return ['message' => 'Email changed. Please sign in again.'];
        }
        $this->service->verify($r->validated('token'));

        return ['message' => 'Email verified.'];
    }

    public function profile(AuthRequest $r)
    {
        $r->user()->update($r->validated());

        return ['data' => $this->user($r->user()->fresh())];
    }

    public function changeEmail(AuthRequest $r)
    {
        $this->service->requestEmailChange($r->user(), $r->validated());

        return ['message' => 'Confirm the link sent to your new email.'];
    }

    public function changePassword(AuthRequest $r)
    {
        $this->service->changePassword($r->user(), $r->validated(), $r);

        return ['message' => 'Password updated.'];
    }
}
