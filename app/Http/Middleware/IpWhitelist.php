<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IpWhitelist
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $allowedIps = config('app.admin_allowed_ips', []);

        if ($allowedIps !== [] && ! in_array($request->ip(), (array) $allowedIps)) {
            // In production, we return a 404 to avoid revealing the existence of the backoffice
            if (app()->isProduction()) {
                abort(404);
            }

            abort(403, 'Your IP address ('.$request->ip().') is not authorized to access this area.');
        }

        return $next($request);
    }
}
