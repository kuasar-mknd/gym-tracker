<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Models\User;
use App\Services\StatsService;

final class FetchDashboardDataAction
{
    public function __construct(
        protected StatsService $statsService
    ) {
    }

    /**
     * Fetch immediate dashboard data for the given user.
     * These are lightweight queries or single-row fetches suitable for initial page load.
     *
     * @return array{
     *     workoutsCount: int,
     *     thisWeekCount: int,
     *     latestWeight: float|string|null,
     *     recentWorkouts: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Workout>,
     *     recentPRs: \Illuminate\Database\Eloquent\Collection<int, \App\Models\PersonalRecord>,
     *     activeGoals: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Goal>
     * }
     */
    public function getImmediateStats(User $user): array
    {
        $latestMeasurement = $user->bodyMeasurements()->latest('measured_at')->first();

        return [
            'workoutsCount' => $user->workouts()->count(),
            'thisWeekCount' => $this->getThisWeekCount($user),
            'latestWeight' => $latestMeasurement?->weight,
            'recentWorkouts' => $this->getRecentWorkouts($user),
            'recentPRs' => $this->getRecentPRs($user),
            'activeGoals' => $this->getActiveGoals($user),
        ];
    }

    /**
     * Get weekly volume comparison stats.
     *
     * @return array{current_week_volume: float, percentage: float|int}
     */
    public function getWeeklyVolumeStats(User $user): array
    {
        $stats = $this->statsService->getWeeklyVolumeComparison($user);

        return [
            'current_week_volume' => $stats['current_week_volume'],
            'percentage' => $stats['percentage'],
        ];
    }

    /**
     * @return array<int, array{date: string, day_label: string, volume: float}>
     */
    public function getWeeklyVolumeTrend(User $user): array
    {
        return $this->statsService->getWeeklyVolumeTrend($user);
    }

    /**
     * @return array<int, array{date: string, day_name: string, volume: float}>
     */
    public function getVolumeTrend(User $user): array
    {
        return $this->statsService->getDailyVolumeTrend($user, 7);
    }

    /**
     * @return array<int, array{label: string, count: int}>
     */
    public function getDurationDistribution(User $user): array
    {
        return $this->statsService->getDurationDistribution($user);
    }

    private function getThisWeekCount(User $user): int
    {
        return $user->workouts()
            ->where('started_at', '>=', now()->startOfWeek())
            ->count();
    }

    /**
     * Get recent Personal Records.
     * Optimized to fetch only the amount displayed on the dashboard (2).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\PersonalRecord>
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
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Goal>
     */
    private function getActiveGoals(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $user->goals()
            ->whereNull('completed_at')
            ->latest()
            ->take(2)
            ->get()
            ->append(['progress', 'unit']);
    }

    /**
     * Get recent workouts.
     * PERFORMANCE OPTIMIZATION: Uses withCount('workoutLines') instead of with('workoutLines')
     * to avoid loading full collections when only the count is needed for UI logic.
     * Limits to 3 items as per dashboard layout.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Workout>
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
