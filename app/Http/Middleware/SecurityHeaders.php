<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
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

        $nonce = strval($this->ensureNonce($request));
        $response->headers->set('Content-Security-Policy', $this->getCsp($request, $nonce));

        return $response;
    }

    /**
     * Ensure a CSP nonce is present in the request.
     */
    protected function ensureNonce(Request $request): string
    {
        $nonce = $request->attributes->get('csp-nonce');

        if (! $nonce) {
            $nonce = Str::random(32);
            $request->attributes->set('csp-nonce', $nonce);
            Vite::useCspNonce($nonce);
        }

        if (is_string($nonce)) {
            return $nonce;
        }

        return '';
    }

    /**
     * Get the CSP string based on the current context.
     */
    protected function getCsp(Request $request, string $nonce): string
    {
        if ($this->isAdminRoute($request)) {
            return $this->getAdminCsp();
        }

        if (app()->environment('local', 'testing')) {
            return $this->getLocalCsp();
        }

        return $this->getProductionCsp($nonce);
    }

    /**
     * Check if the current request is for the admin panel.
     */
    protected function isAdminRoute(Request $request): bool
    {
        return str_contains($request->fullUrl(), 'backoffice') || str_contains((string) $request->header('Referer'), 'backoffice');
    }

    /**
     * Get CSP for admin users.
     */
    protected function getAdminCsp(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://fonts.bunny.net",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://fonts.googleapis.com",
            "img-src 'self' data: https: https://ui-avatars.com",
            "font-src 'self' https://fonts.bunny.net https://fonts.gstatic.com data:",
            "connect-src 'self'".(app()->isLocal() ? ' ws://localhost:* wss://localhost:* http://localhost:*' : ''),
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }

    /**
     * Get CSP for local development.
     */
    protected function getLocalCsp(): string
    {
        return implode('; ', [
            "default-src 'self' http://localhost:5173 ws://localhost:5173",
            "script-src 'self' 'unsafe-eval' http://localhost:5173",
            "style-src 'self' 'unsafe-inline' http://localhost:5173 https://fonts.googleapis.com https://fonts.bunny.net",
            "img-src 'self' data: https: blob: http://localhost:5173",
            "font-src 'self' https://fonts.gstatic.com https://fonts.bunny.net data: http://localhost:5173",
            "connect-src 'self' http://localhost:5173 ws://localhost:5173 https:",
            "frame-src 'self' https:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }

    /**
     * Get strict CSP for production.
     */
    protected function getProductionCsp(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://fonts.googleapis.com",
            "img-src 'self' data: https:",
            "font-src 'self' https://fonts.bunny.net https://fonts.gstatic.com data:",
            "connect-src 'self'".(app()->isLocal() ? ' ws://localhost:* wss://localhost:* http://localhost:*' : ''),
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }
}
