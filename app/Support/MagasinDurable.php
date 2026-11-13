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
    public static function depot(): Repository
    {
        $nom = config('cache.limiter');

        return Cache::store(is_string($nom) && $nom !== '' ? $nom : null);
    }
}
