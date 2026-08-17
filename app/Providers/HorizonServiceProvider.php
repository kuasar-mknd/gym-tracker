<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    #[\Override]
    protected function gate(): void
    {
        /*
         * La liste etait vide, litteralement `in_array($user?->email, [], true)`.
         *
         * Toute alerte sur une file menait donc a une porte fermee : le tableau
         * de bord existait, tournait, et n'etait accessible a personne. Un
         * dispositif d'observation que l'on ne peut pas consulter ne vaut pas
         * mieux que pas de dispositif (#1443).
         *
         * Configurable plutot qu'en dur : ce depot est public, une adresse
         * personnelle n'y a pas sa place. La liste vide reste le defaut, donc
         * une installation qui ne configure rien reste fermee comme avant.
         */
        // La configuration est lue A CHAQUE APPEL, pas une fois au demarrage.
        // Figee a la definition de la porte, la liste devenait intestable — et
        // surtout, changer la variable d'environnement n'aurait eu d'effet
        // qu'apres un redemarrage, ce que rien n'aurait dit.
        Gate::define('viewHorizon', function (?User $user = null): bool {
            if (! $user instanceof User) {
                return false;
            }

            $configurees = config('horizon.allowed_emails', '');

            $autorisees = array_filter(array_map(
                trim(...),
                explode(',', is_string($configurees) ? $configurees : ''),
            ), static fn (string $email): bool => $email !== '');

            return in_array($user->email, $autorisees, true);
        });
    }
}
