<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Chaque clef de statistique porte une version par utilisateur : invalider,
 * c'est incrémenter la version, et toutes les entrées existantes deviennent
 * inatteignables d'un coup. Plus d'énumération manuelle des clefs à oublier,
 * qui avait déjà laissé une entrée derrière elle (#1502).
 *
 * Deux versions : les séances (tout ce qui découle des séances, lignes et
 * séries) et les mesures corporelles, qu'un changement de séance ne périme pas.
 */
final class ClesDeStats
{
    public static function seances(User $user, string $suffixe): string
    {
        return "stats.{$suffixe}.{$user->id}.s".self::version("stats-version.seances.{$user->id}");
    }

    public static function mesures(User $user, string $suffixe): string
    {
        return "stats.{$suffixe}.{$user->id}.m".self::version("stats-version.mesures.{$user->id}");
    }

    public static function invaliderSeances(User $user): void
    {
        Cache::increment("stats-version.seances.{$user->id}");
    }

    public static function invaliderMesures(User $user): void
    {
        Cache::increment("stats-version.mesures.{$user->id}");
    }

    private static function version(string $clef): int
    {
        $version = Cache::get($clef, 0);

        return is_numeric($version) ? (int) $version : 0;
    }
}
