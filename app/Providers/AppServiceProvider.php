<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Gate::before(fn ($user, $ability) => $user->is_active && $user->hasRole('admin') ? true : null);
        ResetPassword::createUrlUsing(fn ($user, $token) => config('store.url').'/api/v1/auth/reset-password?token='.urlencode($token).'&email='.urlencode($user->email));
        RateLimiter::for('auth', fn (Request $r) => [Limit::perMinute(20)->by($r->ip()), Limit::perMinute(5)->by(hash('sha256', strtolower((string) $r->input('email')).'|'.$r->ip()))]);
        RateLimiter::for('links', fn (Request $r) => Limit::perMinute(3)->by($r->user()?->id ?? $r->ip()));
        RateLimiter::for('public-write', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        RateLimiter::for('payments', fn (Request $r) => Limit::perMinute(10)->by($r->user('sanctum')?->id ?? $r->ip()));
        config(['queue.connections.database.retry_after' => 180]);
    }
}
