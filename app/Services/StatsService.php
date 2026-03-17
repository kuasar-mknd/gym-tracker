<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Services\Stats\BodyStatsService;
use App\Services\Stats\ExerciseStatsService;
use App\Services\Stats\StatsCacheManager;
use App\Services\Stats\VolumeStatsService;
use App\Services\Stats\WorkoutStatsService;

/**
 * Service for calculating and retrieving user workout statistics.
 * This service acts as a proxy for specialized stats services.
 */
final readonly class StatsService
{
    public function __construct(
        private VolumeStatsService $volumeStats,
        private BodyStatsService $bodyStats,
        private WorkoutStatsService $workoutStats,
        private ExerciseStatsService $exerciseStats,
        private StatsCacheManager $cacheManager
    ) {
    }

    /**
     * @return array<int, array{date: string, full_date: string, name: string, volume: float}>
     */
    public function getVolumeTrend(User $user, int $days = 30): array
    {
        return $this->volumeStats->getVolumeTrend($user, $days);
    }

    /**
     * @return array<int, array{date: string, day_name: string, volume: float}>
     */
    public function getDailyVolumeTrend(User $user, int $days = 7): array
    {
        return $this->volumeStats->getDailyVolumeTrend($user, $days);
    }

    /**
     * @return array<int, array{category: string, volume: float}>
     */
    public function getMuscleDistribution(User $user, int $days = 30): array
    {
        return $this->exerciseStats->getMuscleDistribution($user, $days);
    }

    /**
     * @return array<int, array{date: string, full_date: string, one_rep_max: float}>
     */
    public function getExercise1RMProgress(User $user, int $exerciseId, int $days = 90): array
    {
        return $this->exerciseStats->getExercise1RMProgress($user, $exerciseId, $days);
    }

    /**
     * @return array{current_month_volume: float, previous_month_volume: float, difference: float, percentage: float}
     */
    public function getMonthlyVolumeComparison(User $user): array
    {
        return $this->volumeStats->getMonthlyVolumeComparison($user);
    }

    /**
     * @return array<int, array{date: string, full_date: string, weight: float}>
     */
    public function getWeightHistory(User $user, int $days = 90): array
    {
        return $this->bodyStats->getWeightHistory($user, $days);
    }

    /**
     * @return array{latest_weight: float|string|null, weight_change: float, latest_body_fat: float|string|null}
     */
    public function getLatestBodyMetrics(User $user): array
    {
        return $this->bodyStats->getLatestBodyMetrics($user);
    }

    /**
     * @return array<int, array{date: string, full_date: string, body_fat: float}>
     */
    public function getBodyFatHistory(User $user, int $days = 90): array
    {
        return $this->bodyStats->getBodyFatHistory($user, $days);
    }

    /**
     * @return array<int, array{date: string, day_label: string, volume: float}>
     */
    public function getWeeklyVolumeTrend(User $user): array
    {
        return $this->volumeStats->getWeeklyVolumeTrend($user);
    }

    /**
     * @return array{current_week_volume: float, previous_week_volume: float, difference: float, percentage: float}
     */
    public function getWeeklyVolumeComparison(User $user): array
    {
        return $this->volumeStats->getWeeklyVolumeComparison($user);
    }

    /**
     * @return array<int, array{date: string, duration: int, name: string}>
     */
    public function getDurationHistory(User $user, int $limit = 20): array
    {
        return $this->workoutStats->getDurationHistory($user, $limit);
    }

    /**
     * @return array<int, array{date: string, volume: float, name: string}>
     */
    public function getVolumeHistory(User $user, int $limit = 20): array
    {
        return $this->volumeStats->getVolumeHistory($user, $limit);
    }

    /**
     * @return array<int, array{label: string, count: int}>
     */
    public function getDurationDistribution(User $user, int $days = 90): array
    {
        return $this->workoutStats->getDurationDistribution($user, $days);
    }

    /**
     * @return array<int, array{label: string, count: int}>
     */
    public function getTimeOfDayDistribution(User $user, int $days = 90): array
    {
        return $this->workoutStats->getTimeOfDayDistribution($user, $days);
    }

    /**
     * @return array<int, array{month: string, volume: float}>
     */
    public function getMonthlyVolumeHistory(User $user, int $months = 6): array
    {
        return $this->volumeStats->getMonthlyVolumeHistory($user, $months);
    }

    /**
     * Clear the stats cache for a user.
     */
    public function clearUserStatsCache(User $user): void
    {
        $this->cacheManager->clearUserStatsCache($user);
    }

    /**
     * Clear only metadata related stats (name, notes).
     */
    public function clearWorkoutMetadataStats(User $user): void
    {
        $this->cacheManager->clearWorkoutMetadataStats($user);
    }

    /**
     * Clear stats cache related to workout volume (sets, weight, reps).
     */
    public function clearVolumeStats(User $user): void
    {
        $this->cacheManager->clearVolumeStats($user);
    }

    /**
     * Clear stats cache related to workout duration and time of day.
     */
    public function clearDurationStats(User $user): void
    {
        $this->cacheManager->clearDurationStats($user);
    }

    /**
     * Clear all stats cache related to workouts.
     */
    public function clearWorkoutRelatedStats(User $user): void
    {
        $this->cacheManager->clearWorkoutRelatedStats($user);
    }

    /**
     * Clear stats cache related to body measurements.
     */
    public function clearBodyMeasurementStats(User $user): void
    {
        $this->cacheManager->clearBodyMeasurementStats($user);
    }
}
