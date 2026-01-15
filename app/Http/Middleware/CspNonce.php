<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generates a unique CSP nonce for each request and configures Vite to use it.
 *
 * This middleware must be registered BEFORE SecurityHeaders in the middleware stack.
 */
class CspNonce
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Generate a unique nonce for this request
        $nonce = Str::random(32);

        // Store nonce in request attributes for use by SecurityHeaders middleware
        $request->attributes->set('csp-nonce', $nonce);

        // Configure Vite to use the nonce for all script tags
        Vite::useCspNonce($nonce);

        return $next($request);
    }
}
