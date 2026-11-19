<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    private const string POLITIQUE_DES_ERREURS = "default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-XSS-Protection', '0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        /**
         * `vr` était le nom du brouillon et ne figure pas au registre
         * Permissions Policy : les navigateurs rejettent le jeton et consignent
         * "Unrecognized feature: 'vr'" sur chacune des réponses — 154 fois sur
         * une seule exécution des tests navigateur. Le nom enregistré de la
         * capacité qu'il s'agissait d'interdire est `xr-spatial-tracking` :
         * tant que cette ligne ne le disait pas, WebXR n'était pas réellement
         * interdit.
         */
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=(), xr-spatial-tracking=()'
        );
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($response->getStatusCode() >= 400 && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', self::POLITIQUE_DES_ERREURS);
        }

        return $response;
    }
}
