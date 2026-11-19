<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Support\AssuranceDuSecondFacteur;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La double authentification obligatoire du panneau (#2353), rejouée sur
 * chaque mise à jour Livewire (middleware persistant).
 *
 * Celui de Filament n'est posé que sur les routes des pages : une mise à jour
 * Livewire (`POST /livewire-…/update`) ne le rejoue pas, et un administrateur
 * qui n'a pas de secret (composant ouvert avant le déploiement, secret effacé
 * par `admin:reinitialiser-mfa`) gardait ses actions. Comme tout middleware
 * persistant, il se pose aussi sur les routes du panneau : la page
 * d'enrôlement, que celui de Filament exempte en n'y étant pas posé, en est
 * exemptée ici, sans quoi l'enrôlement bouclerait. La connexion n'a pas
 * d'administrateur : rien à exiger d'elle.
 *
 * La session doit aussi porter la preuve du second facteur
 * (`AssuranceDuSecondFacteur`) : sans elle, ou périmée par une
 * réinitialisation, l'administrateur est déconnecté et renvoyé à la connexion.
 */
final class ExigeLaDoubleAuthentificationDuPanneau
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $administrateur = Filament::auth()->user();

        if (! $administrateur instanceof Admin) {
            return $next($request);
        }

        if (! AssuranceDuSecondFacteur::estPortee($administrateur)) {
            Filament::auth()->logout();
            $request->session()->forget(AssuranceDuSecondFacteur::CLE);

            return redirect()->to(Filament::getLoginUrl() ?? '/');
        }

        if ($administrateur->aLaDoubleAuthentification()) {
            return $next($request);
        }

        $enrolement = Filament::getSetUpRequiredMultiFactorAuthenticationUrl();

        if ($enrolement === null || $request->url() === $enrolement) {
            return $next($request);
        }

        return redirect()->guest($enrolement);
    }
}
