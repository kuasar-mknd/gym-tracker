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
     * Clear all statistics cache for a given user.
     *
     * @param  User  $user  The user whose stats cache should be cleared.
     * @return void
     */
    public function clearUserStatsCache(User $user): void
    {
        $this->clearWorkoutRelatedStats($user);
        $this->clearWorkoutMetadataStats($user);
        $this->clearBodyMeasurementStats($user);
    }

    /**
     * Clear cache specifically for workout metadata (e.g., name, notes) changes.
     * This affects historical volume and duration limits but not analytical aggregates.
     *
     * @param  User  $user  The user whose workout metadata cache should be cleared.
     * @return void
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
     * @return void
     */
    public function clearVolumeStats(User $user): void
    {
        $weekKey = now()->startOfWeek()->format('Y-W');

        Cache::forget("stats.weekly_volume.{$user->id}");
        Cache::forget("stats.dashboard_analytical.{$user->id}");
        Cache::forget("stats.weekly_volume_comparison.{$user->id}.{$weekKey}");
        Cache::forget("stats.monthly_volume_comparison.{$user->id}");
        Cache::forget("stats.monthly_volume_history.{$user->id}.6");

        Cache::put("stats.1rm_version.{$user->id}", (string) time(), 86400 * 30);

        foreach ([7, 30, 90, 365] as $days) {
            Cache::forget("stats.volume_trend.{$user->id}.{$days}");
            Cache::forget("stats.daily_volume.{$user->id}.{$days}");
            Cache::forget("stats.performance_overview.{$user->id}.{$days}");
        }

        Cache::forget("stats.volume_history.{$user->id}.20");
        Cache::forget("stats.volume_history.{$user->id}.30");

        Cache::forget("stats.muscle_dist.{$user->id}.30");
        Cache::forget("stats.muscle_dist.{$user->id}.7");
    }

    /**
     * Clear cache related to workout duration and time-of-day changes.
     *
     * @param  User  $user  The user whose duration stats cache should be cleared.
     * @return void
     */
    public function clearDurationStats(User $user): void
    {
        Cache::forget("stats.duration_history.{$user->id}.20");
        Cache::forget("stats.workout_distributions.{$user->id}.90");
        Cache::forget("stats.dashboard_analytical.{$user->id}");

        foreach ([7, 30, 90, 365] as $days) {
            Cache::forget("stats.performance_overview.{$user->id}.{$days}");
        }
    }

    /**
     * Clear all workout-related statistics cache (both volume and duration).
     *
     * @param  User  $user  The user whose workout stats cache should be cleared.
     * @return void
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
     * @return void
     */
    public function clearBodyMeasurementStats(User $user): void
    {
        Cache::forget("stats.latest_metrics.{$user->id}");

        foreach ([7, 30, 90, 365] as $days) {
            Cache::forget("stats.weight_history.{$user->id}.{$days}");
            Cache::forget("stats.body_fat_history.{$user->id}.{$days}");
            Cache::forget("stats.body_progress.{$user->id}.{$days}");
        }
    }
}
