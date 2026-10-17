<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * L'échéance d'un cookie, jugée à l'horloge de l'application (#2048).
 *
 * Laravel calcule l'échéance des cookies qu'il pose ou efface à l'horloge de
 * Carbon : la durée de la session, celle du rappel, ou cinq ans en arrière
 * pour un effacement (`CookieJar::forget()`). `Cookie::isCleared()`, de
 * Symfony, la compare à la vraie heure (`time()`). En production, les deux
 * horloges sont la même ; sous une horloge arrêtée (`setTestNow()`), un
 * cookie posé passerait pour effacé, ou l'inverse. Ce jugement-ci lit
 * l'instant que l'appelant a lu, comme un navigateur réglé sur la même
 * horloge que le serveur.
 */
final class EcheanceDuCookie
{
    /**
     * Dit si l'échéance du cookie est passée à `$maintenant` : la même règle
     * que `Cookie::isCleared()`, à une autre horloge. Un cookie sans échéance
     * (0) vit jusqu'à la fin de la session du navigateur, et n'est jamais
     * passé.
     */
    public static function estPassee(Cookie $cookie, CarbonInterface $maintenant): bool
    {
        $echeance = $cookie->getExpiresTime();

        return $echeance !== 0 && $echeance < $maintenant->getTimestamp();
    }
}
