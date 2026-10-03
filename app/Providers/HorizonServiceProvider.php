<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\IpWhitelist;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Qui entre dans Horizon : l'une OU l'autre de deux voies.
     *
     * - Un compte de l'application listé dans `HORIZON_ALLOWED_EMAILS`, par la
     *   porte `viewHorizon` (#1443), sans liste d'adresses, comme avant.
     * - L'administrateur du panneau qui voit le lien « Horizon » de son menu
     *   (capacité `view-outils`), depuis une adresse que `ADMIN_ALLOWED_IPS`
     *   admet : la règle même du panneau, par `IpWhitelist::admet()`. Le lien
     *   menait à un 403, la porte ne regardant que la garde `web`.
     *
     * Le rappel est posé une fois, au démarrage : sous Octane, il sert toutes
     * les requêtes du worker (`Horizon::$authUsing` est statique, et rien ne le
     * remet à zéro). Il ne capture donc rien — ni `$this`, dont l'application
     * est celle de base et non celle de la requête, ni un utilisateur — et lit
     * la requête, la session et la configuration à chaque appel. Horizon
     * l'appelle pour chacune de ses routes, API comprise (le constructeur de
     * son contrôleur de base pose `Laravel\Horizon\Http\Middleware\Authenticate`).
     */
    #[\Override]
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(static fn (Request $request): bool => Gate::check('viewHorizon', [$request->user()])
            || self::ouvertALAdministrateurDuPanneau($request)
            || app()->environment('local'));
    }

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
        // Tout authentifiable, pas seulement `User` : quand la garde par defaut
        // est celle du panneau, la porte recoit un `Admin`, et un parametre
        // type `?User` levait une TypeError, soit un 500 au lieu d'un refus.
        Gate::define('viewHorizon', function (?Authenticatable $user = null): bool {
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

    /**
     * L'administrateur connecté au panneau, qui en voit le lien, depuis une
     * adresse admise sur le panneau.
     *
     * L'adresse d'abord : hors liste, l'administrateur n'est pas même cherché
     * en base. La garde `admin` est nommée, jamais rendue garde par défaut,
     * pour que le reste de la requête — la porte `viewHorizon` comprise — reste
     * sur `web`.
     */
    private static function ouvertALAdministrateurDuPanneau(Request $request): bool
    {
        if (! IpWhitelist::admet($request)) {
            return false;
        }

        $administrateur = $request->user('admin');

        return $administrateur instanceof Admin && $administrateur->can('view-outils');
    }
}
