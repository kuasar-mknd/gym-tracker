<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Stats\BodyFatHistoryPoint;
use App\DTOs\Stats\DailyVolumeTrendPoint;
use App\DTOs\Stats\DistributionStat;
use App\DTOs\Stats\DurationHistoryPoint;
use App\DTOs\Stats\Exercise1RMProgressPoint;
use App\DTOs\Stats\LatestBodyMetrics;
use App\DTOs\Stats\MonthlyVolumePoint;
use App\DTOs\Stats\MuscleDistributionStat;
use App\DTOs\Stats\VolumeComparison;
use App\DTOs\Stats\VolumeHistoryPoint;
use App\DTOs\Stats\VolumeTrendPoint;
use App\DTOs\Stats\WeeklyVolumeTrendPoint;
use App\DTOs\Stats\WeightHistoryPoint;
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
     * @return array<int, VolumeTrendPoint>
     */
    public function getVolumeTrend(User $user, int $days = 30): array
    {
        return $this->volumeStats->getVolumeTrend($user, $days);
    }

    /**
     * @return array<int, DailyVolumeTrendPoint>
     */
    public function getDailyVolumeTrend(User $user, int $days = 7): array
    {
        return $this->volumeStats->getDailyVolumeTrend($user, $days);
    }

    /**
     * @return array<int, MuscleDistributionStat>
     */
    public function getMuscleDistribution(User $user, int $days = 30): array
    {
        return $this->exerciseStats->getMuscleDistribution($user, $days);
    }

    /**
     * @return array<int, Exercise1RMProgressPoint>
     */
    public function getExercise1RMProgress(User $user, int $exerciseId, int $days = 90): array
    {
        return $this->exerciseStats->getExercise1RMProgress($user, $exerciseId, $days);
    }

    public function getMonthlyVolumeComparison(User $user): VolumeComparison
    {
        return $this->volumeStats->getMonthlyVolumeComparison($user);
    }

    /**
     * @return array<int, WeightHistoryPoint>
     */
    public function getWeightHistory(User $user, int $days = 90): array
    {
        return $this->bodyStats->getWeightHistory($user, $days);
    }

    public function getLatestBodyMetrics(User $user): LatestBodyMetrics
    {
        return $this->bodyStats->getLatestBodyMetrics($user);
    }

    /**
     * @return array<int, BodyFatHistoryPoint>
     */
    public function getBodyFatHistory(User $user, int $days = 90): array
    {
        return $this->bodyStats->getBodyFatHistory($user, $days);
    }

    /**
     * @return array<int, WeeklyVolumeTrendPoint>
     */
    public function getWeeklyVolumeTrend(User $user): array
    {
        return $this->volumeStats->getWeeklyVolumeTrend($user);
    }

    public function getWeeklyVolumeComparison(User $user): VolumeComparison
    {
        return $this->volumeStats->getWeeklyVolumeComparison($user);
    }

    /**
     * @return array<int, DurationHistoryPoint>
     */
    public function getDurationHistory(User $user, int $limit = 20): array
    {
        return $this->workoutStats->getDurationHistory($user, $limit);
    }

    /**
     * @return array<int, VolumeHistoryPoint>
     */
    public function getVolumeHistory(User $user, int $limit = 20): array
    {
        return $this->volumeStats->getVolumeHistory($user, $limit);
    }

    /**
     * @return array<int, DistributionStat>
     */
    public function getDurationDistribution(User $user, int $days = 90): array
    {
        return $this->workoutStats->getDurationDistribution($user, $days);
    }

    /**
     * @return array<int, DistributionStat>
     */
    public function getTimeOfDayDistribution(User $user, int $days = 90): array
    {
        return $this->workoutStats->getTimeOfDayDistribution($user, $days);
    }

    /**
     * @return array<int, MonthlyVolumePoint>
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
