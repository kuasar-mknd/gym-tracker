<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Le magasin que ne vide pas un cache à part qui repart à zéro : celui que
 * nomme `cache.limiter` (`redis-durable` en production), le magasin par défaut
 * quand il est vide (#2151).
 */
final class MagasinDurable
{
    /**
     * Le nom du magasin : `cache.limiter`, sinon le défaut.
     */
    public static function nom(): ?string
    {
        $nom = config('cache.limiter');

        if (is_string($nom) && $nom !== '') {
            return $nom;
        }

        $defaut = config('cache.default');

        return is_string($defaut) ? $defaut : null;
    }

    public static function depot(): Repository
    {
        return Cache::store(self::nom());
    }
}
