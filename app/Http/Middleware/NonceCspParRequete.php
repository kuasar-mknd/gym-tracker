<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Laravel\Horizon\Horizon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tire un nonce CSP neuf à chaque requête, avant tout le reste de la pile (#1904).
 *
 * Le nonce était tiré dans `AppServiceProvider::boot()`. Sous Octane, `boot()`
 * ne tourne qu'au démarrage du worker, `Vite` est un singleton, et `FlushVite`
 * ne vide que ses actifs préchargés et ses polices, jamais `$nonce` : chaque
 * worker servait le même nonce à tous les utilisateurs pendant ses cinq cents
 * requêtes. Lu dans le code source de n'importe quelle page, il autorisait un
 * script injecté dans toutes les autres — or c'est la seule barrière de
 * `script-src` contre un script en ligne.
 *
 * `Vite` reste la source unique du nonce : `@vite`, la meta, `@routes`, Filament
 * et Livewire le lisent par `Vite::cspNonce()`, et la CSP de spatie par
 * `ViteNonceGenerator`, au travers de l'instance `csp-nonce`. Cette instance est
 * liée en `scoped` : Octane la vide entre deux requêtes, mais ni le client de
 * test ni un autre processus long ne le font. Sans l'oubli ci-dessous, l'en-tête
 * garderait l'ancien nonce quand la page porterait le nouveau, et le navigateur
 * bloquerait tous les scripts de la page.
 *
 * Horizon écrit son JavaScript dans un `<script type="module">` en ligne, servi
 * sous la pile `web` donc sous cette CSP : il reçoit le même nonce. Son état est
 * statique, mais réécrit ici à chaque requête, donc rien ne passe d'une requête
 * à l'autre.
 *
 * Global et en tête de pile, plutôt que dans le groupe `web` : le panneau
 * Filament, Pulse, Horizon et les mises à jour de Livewire ont chacun leur propre
 * pile, et tout ce qui lit le nonce doit le trouver déjà tiré. Un écouteur
 * `RequestReceived` ou une entrée `octane.flush` n'auraient couvert qu'Octane,
 * et vider tout le singleton `Vite` aurait aussi perdu sa configuration.
 */
class NonceCspParRequete
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        app()->forgetInstance('csp-nonce');

        Horizon::cspNonce($nonce);

        return $next($request);
    }
}
