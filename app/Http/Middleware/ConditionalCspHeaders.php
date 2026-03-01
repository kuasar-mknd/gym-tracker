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
        if (app()->environment('testing')) {
            return $next($request);
        }

        /** @var string $path */
        $path = config('pulse.path', 'backoffice/pulse');

        // Skip global CSP for Pulse routes. Pulse routes have their own
        // CSP middleware registered in config/pulse.php with a custom preset.
        // We only want to skip the GLOBAL instance (which has no custom preset).
        if ($request->is($path.'*') && $customPreset === null) {
            return $next($request);
        }

        return parent::handle($request, $next, $customPreset);
    }
}
