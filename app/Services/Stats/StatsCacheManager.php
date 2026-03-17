<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class StatsCacheManager
{
    public function clearUserStatsCache(User $user): void
    {
        $this->clearWorkoutRelatedStats($user);
        $this->clearWorkoutMetadataStats($user);
        $this->clearBodyMeasurementStats($user);
    }

    public function clearWorkoutMetadataStats(User $user): void
    {
        Cache::forget("stats.volume_history.{$user->id}.20");
        Cache::forget("stats.volume_history.{$user->id}.30");
        Cache::forget("stats.duration_history.{$user->id}.20");

        foreach ([7, 30, 90, 365] as $days) {
            Cache::forget("stats.volume_trend.{$user->id}.{$days}");
        }
    }

    public function clearVolumeStats(User $user): void
    {
        $weekKey = now()->startOfWeek()->format('Y-W');

        Cache::forget("stats.weekly_volume.{$user->id}");
        Cache::forget("stats.weekly_volume_comparison.{$user->id}.{$weekKey}");
        Cache::forget("stats.monthly_volume_comparison.{$user->id}");
        Cache::forget("stats.monthly_volume_history.{$user->id}.6");

        Cache::put("stats.1rm_version.{$user->id}", (string) time(), 86400 * 30);

        foreach ([7, 30, 90, 365] as $days) {
            Cache::forget("stats.volume_trend.{$user->id}.{$days}");
            Cache::forget("stats.daily_volume.{$user->id}.{$days}");
        }

        Cache::forget("stats.volume_history.{$user->id}.20");
        Cache::forget("stats.volume_history.{$user->id}.30");

        Cache::forget("stats.muscle_dist.{$user->id}.30");
        Cache::forget("stats.muscle_dist.{$user->id}.7");
    }

    public function clearDurationStats(User $user): void
    {
        Cache::forget("stats.duration_history.{$user->id}.20");
        Cache::forget("stats.duration_distribution.{$user->id}.90");
        Cache::forget("stats.time_of_day_distribution.{$user->id}.90");
    }

    public function clearWorkoutRelatedStats(User $user): void
    {
        $this->clearVolumeStats($user);
        $this->clearDurationStats($user);
    }

    public function clearBodyMeasurementStats(User $user): void
    {
        Cache::forget("stats.latest_metrics.{$user->id}");

        foreach ([7, 30, 90, 365] as $days) {
            Cache::forget("stats.weight_history.{$user->id}.{$days}");
            Cache::forget("stats.body_fat_history.{$user->id}.{$days}");
        }
    }
}
