<?php

declare(strict_types=1);

namespace App\Support\Sessions;

use Illuminate\Contracts\Cache\Factory as FabriqueDesCaches;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use SessionHandlerInterface;

/**
 * Le gestionnaire des sessions de Laravel, dont chaque magasin, quel que soit
 * le pilote, ne renaît pas de sa destruction (#PR) : `SessionGardee`, ou
 * `SessionChiffreeGardee` quand `session.encrypt` est vrai.
 * `AppServiceProvider` le pose à la place de celui de Laravel.
 *
 * Le gestionnaire de chaque pilote (Redis, base, fichiers, mémoire) reste
 * celui de Laravel, tel quel : Octane lui redonne l'application de chaque
 * requête (`GiveNewApplicationInstanceToDatabaseSessionHandler`), le magasin
 * le reconnaît à sa classe (`ExistenceAwareInterface`, le pilote `cookie`),
 * et l'envelopper les tromperait tous. Seul le magasin change.
 *
 * Les pierres tombales (`PierresTombales`) vivent avec les sessions quand
 * elles vivent dans un cache : même magasin, même connexion, même préfixe ;
 * dans le cache de l'application sinon. Elles durent ce que dure une session.
 */
final class GestionnaireDesSessions extends SessionManager
{
    /**
     * Le magasin d'un pilote, comme Laravel le bâtit, à la classe près.
     *
     * @param  SessionHandlerInterface  $handler
     */
    #[\Override]
    protected function buildSession($handler): Store
    {
        if ((bool) $this->config->get('session.encrypt')) {
            return $this->buildEncryptedSession($handler);
        }

        return new SessionGardee(
            $this->pierresTombalesDe($handler),
            config()->string('session.cookie'),
            $handler,
            config()->string('session.serialization', 'php'),
        );
    }

    /**
     * Le magasin chiffré d'un pilote, comme Laravel le bâtit, à la classe près.
     *
     * @param  SessionHandlerInterface  $handler
     */
    #[\Override]
    protected function buildEncryptedSession($handler): SessionChiffreeGardee
    {
        return new SessionChiffreeGardee(
            $this->pierresTombalesDe($handler),
            config()->string('session.cookie'),
            $handler,
            $this->container->make(Encrypter::class),
            config()->string('session.serialization', 'php'),
        );
    }

    /**
     * Les pierres tombales des sessions de ce gestionnaire : dans leur cache
     * quand elles vivent dans un cache, dans celui de l'application sinon,
     * pour la durée d'une session. Le cache de l'application se résout au
     * premier usage, pas en bâtissant le magasin.
     */
    private function pierresTombalesDe(SessionHandlerInterface $gestionnaire): PierresTombales
    {
        $cache = $gestionnaire instanceof CacheBasedSessionHandler
            ? $gestionnaire->getCache(...)
            : fn (): Repository => $this->container->make(FabriqueDesCaches::class)->store();

        return new PierresTombales($cache, config()->integer('session.lifetime') * 60);
    }
}
