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
    #[\Override]
    public function handle(Request $request, \Closure $next, ?string $customPreset = null): Response
    {
        /** @var string $path */
        $path = config('pulse.path', 'backoffice/pulse');

        // Skip global CSP for Pulse routes. Pulse routes have their own
        // CSP middleware registered in config/pulse.php with a custom preset.
        // We only want to skip the GLOBAL instance (which has no custom preset).
        if ($request->is($path.'*') && $customPreset === null) {
            /*
             * `$next` est un `Closure` sans type de retour declare, donc son
             * resultat est `mixed` — ce que la signature de `handle()` ne
             * promet pas. Le contrat du pipeline Laravel, lui, garantit une
             * `Response` : c'est ce que dit l'annotation, plutot que de laisser
             * l'ecart au baseline.
             *
             * @var Response $reponse
             */
            $reponse = $next($request);

            return $reponse;
        }

        return parent::handle($request, $next, $customPreset);
    }
}
