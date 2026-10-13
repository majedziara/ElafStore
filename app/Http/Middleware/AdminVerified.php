<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminVerified
{
    public function handle(Request $r, Closure $next)
    {
        abort_unless($r->user()?->email_verified_at, 403, 'Verify your administrator email first.');

        return $next($r);
    }
}
