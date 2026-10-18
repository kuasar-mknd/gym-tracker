<?php

declare(strict_types=1);

namespace App\Support\Sessions;

use Closure;
use Illuminate\Contracts\Cache\Repository;

/**
 * Les identifiants de session détruits, gardés le temps qu'une session vit.
 *
 * Laravel enregistre la session à la fin de chaque requête, telle qu'il l'a
 * lue en entrant. Une requête qui l'a lue AVANT qu'une autre la détruise
 * (déconnexion, connexion, suppression du compte, mot de passe changé sur cet
 * appareil, déconnexion du panneau) et qui finit APRÈS la réécrivait : avec le
 * magasin de production, la session détruite renaissait, connexion comprise.
 * La destruction pose donc une pierre tombale sur l'identifiant, dans le même
 * magasin que les sessions quand elles vivent dans un cache (Redis en
 * production), dans le cache de l'application sinon : partagée entre les
 * processus, comme la session elle-même. `SessionGardee` la lit avant de
 * rouvrir un identifiant et après l'avoir enregistré.
 *
 * Elle dure ce que dure une session (`session.lifetime`) : au-delà, la session
 * que l'identifiant nommait aurait expiré d'elle-même, et aucune requête ne
 * dure autant. Une pierre tombale perdue (cache vidé, ou clé évincée par un
 * cache plein) ne rouvre que l'ancien comportement de Laravel pour cet
 * identifiant.
 *
 * Le cache ne se résout qu'au premier usage : bâtir le magasin des sessions,
 * ce que fait toute garde de session qu'une requête consulte, même sans
 * ouvrir de session, ne touche pas au cache.
 *
 * Rien d'une requête à l'autre : de quoi trouver le cache, et la durée.
 */
final readonly class PierresTombales
{
    /**
     * Le préfixe de la clé d'une pierre tombale. Un identifiant de session
     * n'a que des lettres et des chiffres : aucune session ne la porte.
     */
    public const string PREFIXE = 'session-detruite:';

    /**
     * @param  Closure(): Repository  $cache
     */
    public function __construct(
        private Closure $cache,
        private int $dureeEnSecondes,
    ) {
    }

    /**
     * Pose la pierre tombale d'un identifiant, avant que la session soit
     * détruite.
     */
    public function poserSur(string $identifiant): void
    {
        ($this->cache)()->put(self::PREFIXE.$identifiant, true, $this->dureeEnSecondes);
    }

    /**
     * Dit si la session de cet identifiant a été détruite.
     */
    public function estPoseeSur(string $identifiant): bool
    {
        return ($this->cache)()->has(self::PREFIXE.$identifiant);
    }
}
