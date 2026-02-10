<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Spatie\Csp\AddCspHeaders;
use Symfony\Component\HttpFoundation\Response;

class ConditionalCspHeaders extends AddCspHeaders
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, \Closure $next, ?string $customPreset = null): Response
    {
        /** @var string $path */
        $path = config('pulse.path', 'backoffice/pulse');

        // Skip global CSP for Pulse routes as they have their own policy in config/pulse.php
        if ($request->is($path.'*')) {
            return $next($request);
        }

        return parent::handle($request, $next, $customPreset);
    }
}
