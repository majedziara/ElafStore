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
        RateLimiter::for('auth', fn (Request $r) => [Limit::perMinute(20)->by($this->storeClientKey($r)), Limit::perMinute(5)->by(hash('sha256', strtolower((string) $r->input('email')).'|'.$this->storeClientKey($r)))]);
        RateLimiter::for('links', fn (Request $r) => Limit::perMinute(3)->by($r->user()?->id ?? $this->storeClientKey($r)));
        RateLimiter::for('public-write', fn (Request $r) => Limit::perMinute(20)->by($this->storeClientKey($r)));
        RateLimiter::for('payments', fn (Request $r) => Limit::perMinute(10)->by($r->user('sanctum')?->id ?? $this->storeClientKey($r)));
        config(['queue.connections.database.retry_after' => 180]);
    }

    private function storeClientKey(Request $request): string
    {
        $ip = (string) $request->header('X-NV-Client-IP');
        $time = (string) $request->header('X-NV-Client-Time');
        $signature = (string) $request->header('X-NV-Client-Signature');
        $secret = (string) config('store.bff_rate_secret', '');
        if (preg_match('/^[a-f0-9]{64}$/iD', $secret) && filter_var($ip, FILTER_VALIDATE_IP) &&
            ctype_digit($time) && strlen($time) <= 12 && abs(now()->timestamp - (int) $time) <= 60 &&
            preg_match('/^[a-f0-9]{64}$/iD', $signature) && hash_equals(hash_hmac('sha256', $ip.'|'.$time, $secret), $signature)) {
            return $ip;
        }

        return (string) $request->ip();
    }
}
