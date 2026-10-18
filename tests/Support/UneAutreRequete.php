<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Sessions\PierresTombales;
use App\Support\Sessions\SessionChiffreeGardee;
use App\Support\Sessions\SessionGardee;
use App\Support\Sessions\SessionQuiNeRenaitPas;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use ReflectionProperty;
use SessionHandlerInterface;

/**
 * Une autre requête sur la même session, pendant que celle du test tourne :
 * la déconnexion d'un autre onglet, une connexion, la suppression du compte.
 *
 * Une vraie seconde requête ne peut pas partir au milieu de la première dans
 * le client de test. Celle-ci fait ce que ferait son processus : un magasin
 * à elle, de la classe de celui de l'application (`SessionGardee` ou
 * `SessionChiffreeGardee`, sur les mêmes pierres tombales ; le `Store` de
 * Laravel quand la contre-épreuve retire le correctif), sur les mêmes
 * sessions, ouvre la session, la détruit comme la déconnexion
 * (`invalidate()`) ou la connexion (`regenerate(true)`), et enregistre la
 * session neuve qu'il en tire. Son gestionnaire est une
 * autre instance quand le pilote en garde l'état (la base, dont le
 * gestionnaire retient si la ligne existe), comme dans un autre processus ;
 * celui du pilote en mémoire est le même, puisque c'est lui qui tient les
 * sessions.
 *
 * `SessionDetruiteNeRenaitPasTest` et `PrechauffageSansSessionEcriteTest` s'en
 * servent (#PR).
 */
final class UneAutreRequete
{
    /**
     * Détruit maintenant la session de cet identifiant, et rend l'identifiant
     * de la session neuve que l'autre requête enregistre.
     */
    public static function detruitLaSession(string $identifiant, bool $parUneConnexion = false): string
    {
        $autre = self::magasinDUneAutreRequete();
        $autre->setId($identifiant);
        $autre->start();

        if ($parUneConnexion) {
            $autre->regenerate(true);
        } else {
            $autre->invalidate();
        }

        $autre->save();

        return $autre->getId();
    }

    /**
     * Détruira la session de cet identifiant pendant la prochaine requête,
     * juste après que la garde y aura lu le compte (évènement
     * `Authenticated`), une fois.
     */
    public static function detruiraLaSessionPendantLaProchaine(string $identifiant, bool $parUneConnexion = false): void
    {
        $detruite = false;

        Event::listen(Authenticated::class, static function () use ($identifiant, $parUneConnexion, &$detruite): void {
            if (! $detruite) {
                $detruite = true;
                self::detruitLaSession($identifiant, $parUneConnexion);
            }
        });
    }

    /**
     * Ajoute ces valeurs à la session de cet identifiant, et l'enregistre.
     *
     * @param  array<string, mixed>  $valeurs
     */
    public static function ajouteALaSession(string $identifiant, array $valeurs): void
    {
        $autre = self::magasinDUneAutreRequete();
        $autre->setId($identifiant);
        $autre->start();
        $autre->put($valeurs);
        $autre->save();
    }

    /**
     * Ce que le magasin des sessions garde sous cet identifiant, lu comme une
     * autre requête le lirait : la chaîne vide quand il n'y a rien.
     */
    public static function litLaSession(string $identifiant): string
    {
        $contenu = self::gestionnaireDUneAutreRequete()->read($identifiant);

        return is_string($contenu) ? $contenu : '';
    }

    private static function magasinDUneAutreRequete(): Store
    {
        $magasin = self::magasinDeLApplication();
        $gestionnaire = self::gestionnaireDUneAutreRequete();

        if (! $magasin instanceof SessionQuiNeRenaitPas) {
            return new Store($magasin->getName(), $gestionnaire);
        }

        $pierresTombales = new ReflectionProperty($magasin, 'pierresTombales')->getValue($magasin);

        if (! $pierresTombales instanceof PierresTombales) {
            throw new LogicException('Une session qui ne renaît pas tient ses pierres tombales.');
        }

        return $magasin instanceof SessionChiffreeGardee
            ? new SessionChiffreeGardee($pierresTombales, $magasin->getName(), $gestionnaire, app(Encrypter::class))
            : new SessionGardee($pierresTombales, $magasin->getName(), $gestionnaire);
    }

    private static function magasinDeLApplication(): Store
    {
        $magasin = app('session')->driver();

        if (! $magasin instanceof Store) {
            throw new LogicException('Le magasin des sessions de l’application est un Store.');
        }

        return $magasin;
    }

    private static function gestionnaireDUneAutreRequete(): SessionHandlerInterface
    {
        $gestionnaire = self::magasinDeLApplication()->getHandler();
        $duree = config()->integer('session.lifetime');
        $connexion = config('session.connection');

        return match (true) {
            $gestionnaire instanceof DatabaseSessionHandler => new DatabaseSessionHandler(
                DB::connection(is_string($connexion) ? $connexion : null),
                config()->string('session.table'),
                $duree,
                app(),
            ),
            $gestionnaire instanceof CacheBasedSessionHandler => new CacheBasedSessionHandler($gestionnaire->getCache(), $duree),
            default => $gestionnaire,
        };
    }
}
