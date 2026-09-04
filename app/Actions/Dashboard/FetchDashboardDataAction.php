<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Models\User;
use App\Services\Stats\BodyStatsService;
use App\Services\Stats\VolumeStatsService;
use App\Services\Stats\WorkoutStatsService;

/**
 * Action class responsible for fetching and aggregating all necessary data
 * to populate the user dashboard.
 *
 * This action separates immediate, lightweight data requirements from
 * heavier, deferred analytical queries.
 */
final class FetchDashboardDataAction
{
    /**
     * Create a new FetchDashboardDataAction instance.
     *
     * @param  \App\Services\Stats\BodyStatsService  $bodyStats  The latest body metrics.
     * @param  \App\Services\Stats\VolumeStatsService  $volumeStats  Weekly volume trend and comparison.
     * @param  \App\Services\Stats\WorkoutStatsService  $workoutStats  Workout distributions.
     */
    public function __construct(
        protected BodyStatsService $bodyStats,
        protected VolumeStatsService $volumeStats,
        protected WorkoutStatsService $workoutStats
    ) {
    }

    /**
     * Fetch immediate dashboard data for the given user.
     * These are lightweight queries or single-row fetches suitable for initial page load.
     *
     * @param  \App\Models\User  $user  The authenticated user for whom to fetch data.
     * @return array{
     *     latestWeight: float|string|null,
     *     recentWorkouts: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Workout>,
     *     recentPRs: \Illuminate\Database\Eloquent\Collection<int, \App\Models\PersonalRecord>,
     *     activeGoals: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Goal>
     * }
     */
    public function getImmediateStats(User $user): array
    {
        // ⚡ Bolt: Use cached latest metrics instead of hitting DB on every dashboard load
        $latestMetrics = $this->bodyStats->getLatestBodyMetrics($user);

        return [
            // ⚡ Bolt: Removed unused workoutsCount and thisWeekCount queries to prevent 2 unnecessary queries on dashboard load
            'latestWeight' => $latestMetrics->latest_weight ?? null,
            'recentWorkouts' => $this->getRecentWorkouts($user),
            'recentPRs' => $this->getRecentPRs($user),
            'activeGoals' => $this->getActiveGoals($user),
        ];
    }

    /**
     * Get consolidated weekly volume data (stats + trend).
     *
     * @param  \App\Models\User  $user  The authenticated user.
     * @return array{stats: array{current_week_volume: float, percentage: float|null}, trend: array<int, \App\DTOs\Stats\WeeklyVolumeTrendPoint>}
     */
    public function getWeeklyVolumeData(User $user): array
    {
        $stats = $this->volumeStats->getWeeklyVolumeComparison($user);

        return [
            'stats' => [
                'current_week_volume' => $stats->current_volume,
                'percentage' => $stats->percentage,
            ],
            'trend' => $this->volumeStats->getWeeklyVolumeTrend($user),
        ];
    }

    /**
     * Get consolidated analytical stats for the dashboard.
     * ⚡ Bolt: Reduces 2 deferred prop XHR requests to 1 and uses a single cache key.
     *
     * @param  User  $user  The authenticated user.
     * @return array{
     *     weeklyVolume: array{stats: array{current_week_volume: float, percentage: float|null}, trend: array<int, \App\DTOs\Stats\WeeklyVolumeTrendPoint>},
     *     workoutDistributions: array{duration: array<int, \App\DTOs\Stats\DistributionStat>, time_of_day: array<int, \App\DTOs\Stats\DistributionStat>}
     * }
     */
    public function getAnalyticalStats(User $user): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.dashboard_analytical.{$user->id}",
            now()->addMinutes(10),
            fn (): array => [
                'weeklyVolume' => $this->getWeeklyVolumeData($user),
                'workoutDistributions' => $this->getWorkoutDistributions($user),
            ]
        );
    }

    /**
     * Get consolidated workout distributions (duration + time of day).
     *
     * @param  \App\Models\User  $user  The authenticated user.
     * @return array{duration: array<int, \App\DTOs\Stats\DistributionStat>, time_of_day: array<int, \App\DTOs\Stats\DistributionStat>}
     */
    public function getWorkoutDistributions(User $user): array
    {
        return $this->workoutStats->getWorkoutDistributions($user, 90);
    }

    /**
     * Get recent Personal Records.
     * Optimized to fetch only the amount displayed on the dashboard (2).
     *
     * @param  \App\Models\User  $user  The authenticated user.
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\PersonalRecord> A collection of the most recent personal records.
     */
    private function getRecentPRs(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $user->personalRecords()
            ->with('exercise')
            ->latest('achieved_at')
            ->take(2)
            ->get();
    }

    /**
     * Get active goals.
     * Optimized to fetch only the amount displayed on the dashboard (2).
     *
     * @param  \App\Models\User  $user  The authenticated user.
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Goal> A collection of currently active user goals.
     */
    private function getActiveGoals(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $user->goals()
            ->with('exercise')
            ->whereNull('completed_at')
            ->latest()
            ->take(2)
            ->get()
            ->append(['unit']);
    }

    /**
     * Get recent workouts.
     * PERFORMANCE OPTIMIZATION: Uses withCount('workoutLines') instead of with('workoutLines')
     * to avoid loading full collections when only the count is needed for UI logic.
     * Limits to 3 items as per dashboard layout.
     *
     * @param  \App\Models\User  $user  The authenticated user.
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Workout> A collection of the most recent workouts.
     */
    private function getRecentWorkouts(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $user->workouts()
            ->withCount('workoutLines')
            ->latest('started_at')
            ->limit(3)
            ->get();
    }
}
