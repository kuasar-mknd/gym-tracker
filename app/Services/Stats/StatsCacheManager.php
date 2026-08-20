<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Manager for handling statistics cache invalidation.
 *
 * This service provides granular cache clearing capabilities for various
 * user statistics (e.g., volume, duration, body measurements) to ensure
 * dashboards and charts display accurate, up-to-date data without
 * necessarily nuking all cached information simultaneously.
 */
final class StatsCacheManager
{
    /**
     * Clear cache specifically for workout metadata (e.g., name, notes) changes.
     * This affects historical volume and duration limits but not analytical aggregates.
     *
     * @param  User  $user  The user whose workout metadata cache should be cleared.
     */
    public function clearWorkoutMetadataStats(User $user): void
    {
        Cache::forget("stats.volume_history.{$user->id}.20");
        Cache::forget("stats.volume_history.{$user->id}.30");
        Cache::forget("stats.duration_history.{$user->id}.20");

        foreach ([7, 30, 90, 365] as $days) {
            Cache::forget("stats.volume_trend.{$user->id}.{$days}");
        }
    }

    /**
     * Clear cache related to workout volume changes (e.g., sets, weight, reps).
     * This invalidates weekly/monthly comparisons, trends, daily volume, and muscle distribution.
     *
     * @param  User  $user  The user whose volume stats cache should be cleared.
     */
    /**
     * Les periodes que l'application sait demander.
     *
     * Elles etaient recopiees a la main d'un endroit a l'autre, et la copie a
     * derive : `volume_trend` et `performance_overview` oubliaient bien les
     * quatre, `muscle_dist` seulement deux. Les entrees a 90 et 365 jours
     * n'etaient donc JAMAIS invalidees, alors que `getPerformanceOverview()`
     * les ecrit (#1502).
     *
     * @var list<int>
     */
    private const array PERIODES = [7, 30, 90, 365];

    /**
     * Les bornes que les historiques savent recevoir.
     *
     * Meme histoire : `volume_history` oubliait bien 20 et 30,
     * `duration_history` seulement 20.
     *
     * @var list<int>
     */
    private const array BORNES = [20, 30];

    public function clearVolumeStats(User $user): void
    {
        $weekKey = now()->startOfWeek()->format('Y-W');

        Cache::forget("stats.weekly_volume.{$user->id}");
        Cache::forget("stats.dashboard_analytical.{$user->id}");
        Cache::forget("stats.weekly_volume_comparison.{$user->id}.{$weekKey}");
        /*
         * `stats.monthly_volume_comparison` n'est plus oubliee ici : elle
         * n'etait JAMAIS ecrite. `getMonthlyVolumeComparison()` appelle
         * `calculateComparison()` en direct, sans cache, contrairement a sa
         * jumelle hebdomadaire. L'oubli etait un no-op, et deux tests
         * l'affirmaient — ils verifiaient qu'on oublie une entree qui n'existe
         * pas (#1502).
         *
         * Le choix inverse — la mettre en cache pour ressembler a sa jumelle —
         * a ete ecarte : c'est une seule requete d'agregat conditionnel sur une
         * colonne deja calculee, bornee a deux mois. La mettre en cache
         * ajouterait une obligation d'invalidation, c'est-a-dire exactement la
         * classe de defaut que cette correction traite.
         */
        Cache::forget("stats.monthly_volume_history.{$user->id}.6");

        Cache::put("stats.1rm_version.{$user->id}", (string) time(), 86400 * 30);

        foreach (self::PERIODES as $days) {
            Cache::forget("stats.volume_trend.{$user->id}.{$days}");
            Cache::forget("stats.performance_overview.{$user->id}.{$days}");
            Cache::forget("stats.muscle_dist.{$user->id}.{$days}");
        }

        foreach (self::BORNES as $limite) {
            Cache::forget("stats.volume_history.{$user->id}.{$limite}");
        }
    }

    /**
     * Clear cache related to workout duration and time-of-day changes.
     *
     * @param  User  $user  The user whose duration stats cache should be cleared.
     */
    public function clearDurationStats(User $user): void
    {
        Cache::forget("stats.workout_distributions.{$user->id}.90");
        Cache::forget("stats.dashboard_analytical.{$user->id}");

        foreach (self::BORNES as $limite) {
            Cache::forget("stats.duration_history.{$user->id}.{$limite}");
        }

        foreach (self::PERIODES as $days) {
            Cache::forget("stats.performance_overview.{$user->id}.{$days}");
        }
    }

    /**
     * Clear all workout-related statistics cache (both volume and duration).
     *
     * @param  User  $user  The user whose workout stats cache should be cleared.
     */
    public function clearWorkoutRelatedStats(User $user): void
    {
        $this->clearVolumeStats($user);
        $this->clearDurationStats($user);
    }

    /**
     * Clear cache related to body measurements (e.g., weight, body fat).
     *
     * @param  User  $user  The user whose body measurement stats cache should be cleared.
     */
    public function clearBodyMeasurementStats(User $user): void
    {
        Cache::forget("stats.latest_metrics.{$user->id}");

        foreach ([7, 30, 90, 365] as $days) {
            Cache::forget("stats.body_progress.{$user->id}.{$days}");
        }
    }
}
