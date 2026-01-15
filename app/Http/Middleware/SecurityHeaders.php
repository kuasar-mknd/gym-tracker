<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer-when-downgrade');

        // Build CSP with nonce if available, otherwise fall back to unsafe-inline
        $nonce = $request->attributes->get('csp-nonce');

        if ($nonce) {
            // HARDENED CSP: Uses nonce instead of unsafe-inline/unsafe-eval
            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}'",
                "style-src 'self' https://fonts.bunny.net".(app()->isLocal() ? " 'unsafe-inline'" : " 'nonce-{$nonce}'"),
                "img-src 'self' data: https:",
                "font-src 'self' https://fonts.bunny.net",
                "connect-src 'self' ws://localhost:* wss://localhost:* http://localhost:*",
            ]);
        } else {
            // Fallback for non-web requests (API, console, etc.)
            $csp = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self';";
        }

        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
