<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IpWhitelist
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $allowedIps = (array) config('app.admin_allowed_ips', []);

        // En production, une liste vide ferme le panneau : le laisser ouvert
        // au monde par oubli d'une variable serait l'inverse d'une liste blanche.
        if ($allowedIps === [] && app()->isProduction()) {
            abort(404);
        }

        if ($allowedIps !== [] && ! in_array($request->ip(), $allowedIps, true)) {
            if (app()->isProduction()) {
                abort(404);
            }

            abort(403, 'Your IP address ('.$request->ip().') is not authorized to access this area.');
        }

        return $next($request);
    }
}
