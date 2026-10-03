<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\TempsDuServeur\MesureDuTempsServeur;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Validated;
use Illuminate\Auth\Events\Verified;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\ServiceProvider;

/**
 * L'en-tête `Server-Timing` (#1315) : la mesure d'une requête, ses écouteurs.
 *
 * Les écouteurs se posent ici, une fois, au démarrage de l'application —
 * sous Octane, au démarrage du worker. Posés par le middleware, ils
 * s'empileraient : le répartiteur d'événements survit aux requêtes, et chaque
 * requête SQL serait comptée une fois de plus à chaque requête servie.
 *
 * Coupée (`SERVER_TIMING_ENABLED` absente, vide ou fausse), rien ne se pose :
 * le coût par requête se réduit à la lecture de la configuration par le
 * middleware. La configuration est figée au démarrage du conteneur, ce choix
 * aussi.
 *
 * Chaque écouteur cherche la mesure dans le conteneur courant, jamais dans
 * `$this->app` : sous Octane, ce fournisseur tient l'application de base,
 * d'où chaque requête est clonée. Y résoudre la mesure la ferait survivre à
 * la requête et la partagerait entre toutes ; le conteneur courant est le
 * clone de la requête, qu'Octane jette ensuite.
 */
final class TempsDuServeurServiceProvider extends ServiceProvider
{
    /**
     * Toute étape d'une authentification, réussie ou non, sauf
     * `Authenticated`, que chaque requête d'un utilisateur déjà connecté émet
     * en lisant sa session.
     */
    private const array EVENEMENTS_D_AUTHENTIFICATION = [
        Attempting::class,
        Validated::class,
        Failed::class,
        Lockout::class,
        Login::class,
        Logout::class,
        CurrentDeviceLogout::class,
        OtherDeviceLogout::class,
        Registered::class,
        Verified::class,
        PasswordReset::class,
        PasswordResetLinkSent::class,
    ];

    #[\Override]
    public function register(): void
    {
        $this->app->scoped(MesureDuTempsServeur::class);
    }

    public function boot(Dispatcher $evenements): void
    {
        if (config('app.temps_serveur') !== true) {
            return;
        }

        $evenements->listen(QueryExecuted::class, static function (QueryExecuted $requete): void {
            self::mesure()?->compterUneRequeteSql($requete->time);
        });

        $evenements->listen(RouteMatched::class, static function (): void {
            self::mesure()?->noterLaRouteTrouvee();
        });

        $evenements->listen(PreparingResponse::class, static function (): void {
            self::mesure()?->noterLaReponseDuControleur();
        });

        $evenements->listen(self::EVENEMENTS_D_AUTHENTIFICATION, static function (): void {
            self::mesure()?->noterUneAuthentification();
        });
    }

    /**
     * La mesure de la requête en cours, si le middleware en a ouvert une dans
     * le conteneur courant ; jamais une mesure neuve. Hors d'une requête — une
     * tâche de file, une commande, une requête SQL du worker sur l'application
     * de base —, la créer ici la laisserait dans cette application, d'où
     * Octane la recopierait dans chaque requête suivante.
     */
    private static function mesure(): ?MesureDuTempsServeur
    {
        $conteneur = Container::getInstance();

        return $conteneur->resolved(MesureDuTempsServeur::class)
            ? $conteneur->make(MesureDuTempsServeur::class)
            : null;
    }
}
