<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\Models\User;
use App\Support\VersionDeCache;

/**
 * Chaque clef de statistique porte une version par utilisateur : invalider,
 * c'est tirer une version neuve, et toutes les entrées existantes deviennent
 * inatteignables d'un coup. Plus d'énumération manuelle des clefs à oublier,
 * qui avait déjà laissé une entrée derrière elle (#1502).
 *
 * Deux versions : les séances (tout ce qui découle des séances, lignes et
 * séries) et les mesures corporelles, qu'un changement de séance ne périme pas.
 * Ce sont des ULID, jamais réutilisés : une version que le cache perd ne
 * ramène plus une entrée d'avant une invalidation (`VersionDeCache`, #2151).
 */
final class ClesDeStats
{
    public static function seances(User $user, string $suffixe): string
    {
        return "stats.{$suffixe}.{$user->id}.s".VersionDeCache::lire("stats-version.seances.{$user->id}");
    }

    public static function mesures(User $user, string $suffixe): string
    {
        return "stats.{$suffixe}.{$user->id}.m".VersionDeCache::lire("stats-version.mesures.{$user->id}");
    }

    public static function invaliderSeances(User $user): void
    {
        self::invaliderSeancesDuCompte($user->id);
    }

    /**
     * Le même renouvellement, par l'identifiant : le compte peut ne plus
     * exister quand le rappel d'après validation s'exécute.
     */
    public static function invaliderSeancesDuCompte(int $idUtilisateur): void
    {
        VersionDeCache::renouveler("stats-version.seances.{$idUtilisateur}");
    }

    public static function invaliderMesures(User $user): void
    {
        VersionDeCache::renouveler("stats-version.mesures.{$user->id}");
    }
}
