<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ActiveUser
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user() && $request->user()->is_active, 403, 'Account disabled.');

        return $next($request);
    }
}
