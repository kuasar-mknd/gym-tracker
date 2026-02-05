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

    public function getWeeklyVolumeTrend(User $user): array
    {
        return $this->statsService->getWeeklyVolumeTrend($user);
    }

    public function getVolumeTrend(User $user): array
    {
        return $this->statsService->getDailyVolumeTrend($user, 7);
    }

    public function getDurationDistribution(User $user): array
    {
        return $this->statsService->getDurationDistribution($user);
    }

    /**
     * Legacy method if needed, but we will update the controller.
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
                'durationDistribution' => $this->getDurationDistribution($user),
            ]
        );
    }

    private function getThisWeekCount(User $user): int
    {
        return $user->workouts()
            ->where('started_at', '>=', now()->startOfWeek())
            ->count();
    }

    private function getRecentPRs(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $user->personalRecords()
            ->with('exercise')
            ->latest('achieved_at')
            ->take(2)
            ->get();
    }

    private function getActiveGoals(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $user->goals()
            ->whereNull('completed_at')
            ->latest()
            ->take(2)
            ->get()
            ->append(['progress', 'unit']);
    }

    private function getRecentWorkouts(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $user->workouts()
            ->withCount('workoutLines')
            ->latest('started_at')
            ->limit(3)
            ->get();
    }
}
