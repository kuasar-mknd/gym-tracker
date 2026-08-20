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
             * `Response` : c'est ce que dit l'annotation.
             *
             * Elle etait deja ecrite, dans ce bloc-ci, et ne servait a rien :
             * un `@var` n'est lu que dans un docbloc `/**`, pas dans un
             * commentaire `/*`. L'erreur restait donc au baseline malgre
             * l'annotation — d'ou la ligne separee ci-dessous.
             */
            /** @var Response $reponse */
            $reponse = $next($request);

            return $reponse;
        }

        /*
         * Meme chose une marche plus haut : `AddCspHeaders::handle()` de Spatie
         * ne declare aucun type de retour, alors que notre redefinition promet
         * une `Response`. Elle rend ce que lui rend `$next`, c'est-a-dire la
         * meme garantie de pipeline que ci-dessus.
         */
        /** @var Response $reponse */
        $reponse = parent::handle($request, $next, $customPreset);

        return $reponse;
    }
}
