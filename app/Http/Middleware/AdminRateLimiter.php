<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AdminRateLimiter
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'admin-panel:'.($request->ip() ?? 'unknown');

        if (RateLimiter::tooManyAttempts($key, 30)) {
            abort(429, 'Too Many Requests');
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
