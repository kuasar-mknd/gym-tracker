<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Models\User;
use App\Services\StatsService;

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
     * @param  \App\Services\StatsService  $statsService  The statistics service used to calculate trends and comparisons.
     */
    public function __construct(
        protected StatsService $statsService
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
        $latestMetrics = $this->statsService->getLatestBodyMetrics($user);

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
     * @return array{stats: array{current_week_volume: float, percentage: float|int}, trend: array<int, \App\DTOs\Stats\WeeklyVolumeTrendPoint>}
     */
    public function getWeeklyVolumeData(User $user): array
    {
        $stats = $this->statsService->getWeeklyVolumeComparison($user);

        return [
            'stats' => [
                'current_week_volume' => $stats->current_volume,
                'percentage' => $stats->percentage,
            ],
            'trend' => $this->statsService->getWeeklyVolumeTrend($user),
        ];
    }

    /**
     * Get consolidated analytical stats for the dashboard.
     * ⚡ Bolt: Reduces 2 deferred prop XHR requests to 1 and uses a single cache key.
     *
     * @param  User  $user  The authenticated user.
     * @return array{
     *     weeklyVolume: array{stats: array{current_week_volume: float, percentage: float|int}, trend: array<int, \App\DTOs\Stats\WeeklyVolumeTrendPoint>},
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
        return $this->statsService->getWorkoutDistributions($user, 90);
    }

    /**
     * Get weekly volume comparison stats.
     *
     * Calculates the total workout volume for the current week and compares it
     * against the previous week to determine a percentage change.
     *
     * @param  \App\Models\User  $user  The authenticated user.
     * @return array{current_week_volume: float, percentage: float|int} Array containing the current week's volume and the percentage change.
     */
    public function getWeeklyVolumeStats(User $user): array
    {
        $stats = $this->statsService->getWeeklyVolumeComparison($user);

        return [
            'current_week_volume' => $stats->current_volume,
            'percentage' => $stats->percentage,
        ];
    }

    /**
     * Get the weekly volume trend data for chart visualization.
     *
     * Retrieves the daily workout volume for each day of the current week.
     *
     * @param  \App\Models\User  $user  The authenticated user.
     * @return array<int, \App\DTOs\Stats\WeeklyVolumeTrendPoint> A list of daily volumes formatted for a trend chart.
     */
    public function getWeeklyVolumeTrend(User $user): array
    {
        return $this->statsService->getWeeklyVolumeTrend($user);
    }

    /**
     * Get the daily volume trend over the last 7 days.
     *
     * Retrieves a rolling 7-day trend of daily workout volume.
     *
     * @param  \App\Models\User  $user  The authenticated user.
     * @return array<int, \App\DTOs\Stats\DailyVolumeTrendPoint> A list of daily volumes for the past week.
     */
    public function getVolumeTrend(User $user): array
    {
        return $this->statsService->getDailyVolumeTrend($user, 7);
    }

    /**
     * Execute the action to fetch all dashboard data simultaneously.
     *
     * Legacy method that synchronously loads all dashboard stats, including
     * heavy analytical queries. Preferred approach is to use individual methods
     * with Inertia's deferred loading.
     *
     * @param  \App\Models\User  $user  The authenticated user.
     * @return array<string, mixed> All dashboard data aggregated into a single array.
     */
    public function execute(User $user): array
    {
        $weeklyStats = $this->getWeeklyVolumeStats($user);

        return array_merge(
            $this->getImmediateStats($user),
            [
                'weeklyVolume' => $weeklyStats['current_week_volume'],
                'volumeChange' => $weeklyStats['percentage'],
                'weeklyVolumeTrend' => $this->getWeeklyVolumeTrend($user),
                'volumeTrend' => $this->getVolumeTrend($user),
                'workoutDistributions' => $this->getWorkoutDistributions($user),
            ]
        );
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
