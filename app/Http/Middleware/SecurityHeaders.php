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

        if (str_contains($request->path(), 'backoffice')) {
            // ADMIN / BACKOFFICE: Relaxed CSP (Allows Alpine/Livewire eval + Avatars)
            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://fonts.bunny.net",
                "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://fonts.googleapis.com",
                "img-src 'self' data: https: https://ui-avatars.com",
                "font-src 'self' https://fonts.bunny.net https://fonts.gstatic.com data:",
                "connect-src 'self'".(app()->isLocal() ? ' ws://localhost:* wss://localhost:* http://localhost:*' : ''),
                "base-uri 'self'",
                "form-action 'self'",
            ]);
        } elseif (app()->environment('local', 'testing')) {
            $csp = implode('; ', [
                "default-src 'self' http://localhost:5173 ws://localhost:5173",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173",
                "style-src 'self' 'unsafe-inline' http://localhost:5173 https://fonts.googleapis.com https://fonts.bunny.net",
                "img-src 'self' data: https: blob: http://localhost:5173",
                "font-src 'self' https://fonts.gstatic.com https://fonts.bunny.net data: http://localhost:5173",
                "connect-src 'self' http://localhost:5173 ws://localhost:5173 https:",
                "frame-src 'self' https:",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ]);
        } elseif ($nonce) {
            // FRONTEND (Production): Strict CSP with Nonce
            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}'", // No unsafe-eval here!
                // External font stylesheets (fonts.bunny.net) require 'unsafe-inline' as they can't have nonces
                "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://fonts.googleapis.com",
                "img-src 'self' data: https:",
                "font-src 'self' https://fonts.bunny.net https://fonts.gstatic.com data:",
                "connect-src 'self'".(app()->isLocal() ? ' ws://localhost:* wss://localhost:* http://localhost:*' : ''),
            ]);
        } else {
            // Fallback for non-web requests (API, console, etc.)
            $csp = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self';";
        }

        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
