<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\IpWhitelist;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Qui entre dans Horizon : l'une OU l'autre de deux voies.
     *
     * - Un compte de l'application listé dans `HORIZON_ALLOWED_EMAILS`, qui a
     *   confirmé son adresse, par la porte `viewHorizon` (#1443), sans liste
     *   d'adresses IP.
     * - L'administrateur du panneau qui voit le lien « Horizon » de son menu
     *   (capacité `view-outils`), depuis une adresse que `ADMIN_ALLOWED_IPS`
     *   admet (la règle même du panneau, par `IpWhitelist::admet()`), avec une
     *   session que le changement de son mot de passe n'a pas invalidée. Le
     *   lien menait à un 403, la porte ne regardant que la garde `web`.
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

            return self::adresseConfirmee($user) && in_array($user->email, $autorisees, true);
        });
    }

    /**
     * Le compte a-t-il confirmé détenir son adresse ?
     *
     * La liste nomme des adresses, pas des comptes : une adresse listée sans
     * compte, parce que son compte n'a jamais été créé, a été supprimé ou a
     * changé d'adresse, s'inscrit par mot de passe sans en détenir la boîte.
     * Seul le lien de vérification, envoyé à cette adresse, prouve qu'on la
     * détient. Un changement d'adresse depuis le profil retire cette preuve :
     * le compte qui se donne une adresse listée reste dehors jusqu'à ce qu'il
     * l'ait confirmée.
     */
    private static function adresseConfirmee(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    /**
     * L'administrateur connecté au panneau, qui en voit le lien, depuis une
     * adresse admise sur le panneau, avec une session que le panneau
     * accepterait.
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

        return $administrateur instanceof Admin
            && self::sessionValideAuPanneau($request, $administrateur)
            && $administrateur->can('view-outils');
    }

    /**
     * La session porte l'empreinte du mot de passe actuel de l'administrateur,
     * comme le panneau l'exige.
     *
     * Le panneau passe par `Filament\Http\Middleware\AuthenticateSession` :
     * quand l'administrateur change son mot de passe, toute autre session est
     * déconnectée à sa requête suivante. Horizon ne peut pas porter ce
     * middleware, qui vérifie la garde par défaut, `web` ici : la même
     * comparaison est donc refaite sur la garde `admin`, sans quoi une session
     * volée que le panneau rejette ouvrirait encore les tâches d'Horizon.
     *
     * L'empreinte (`password_hash_admin`) est un HMAC du hachage du mot de
     * passe, posé par la connexion (`SessionGuard::login()`) et tenu à jour
     * par `AuthenticateSession` à chaque page du panneau. La page de profil de
     * Filament, quand le mot de passe change, y écrit le hachage lui-même :
     * les deux formes sont admises, comme
     * `AuthenticateSession::validatePasswordHash()` les admet. Une session
     * sans empreinte, que le panneau n'a jamais vue, est refusée.
     */
    private static function sessionValideAuPanneau(Request $request, Admin $administrateur): bool
    {
        $garde = Auth::guard('admin');
        $empreinte = $request->hasSession() ? $request->session()->get('password_hash_admin') : null;
        $motDePasse = $administrateur->getAuthPassword();

        if (! $garde instanceof SessionGuard || ! is_string($empreinte) || $motDePasse === '') {
            return false;
        }

        return hash_equals($garde->hashPasswordForCookie($motDePasse), $empreinte)
            || hash_equals($motDePasse, $empreinte);
    }
}
