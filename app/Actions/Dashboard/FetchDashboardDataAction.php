<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Models\User;
use App\Services\StatsService;
use Illuminate\Support\Facades\Cache;

final class FetchDashboardDataAction
{
    public function __construct(
        protected StatsService $statsService
    ) {
    }

    /**
     * Fetch dashboard data for the given user.
     *
     * @return array{
     *     workoutsCount: int,
     *     thisWeekCount: int,
     *     latestWeight: float|string|null,
     *     recentWorkouts: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Workout>,
     *     recentPRs: \Illuminate\Database\Eloquent\Collection<int, \App\Models\PersonalRecord>,
     *     activeGoals: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Goal>,
     *     weeklyVolume: float,
     *     volumeChange: float|int,
     *     weeklyVolumeTrend: array<int, array{date: string, day_label: string, volume: float}>,
     *     volumeTrend: array<int, array{date: string, day_name: string, volume: float}>,
     *     durationDistribution: array<int, array{label: string, count: int}>
     * }
     */
    public function execute(User $user): array
    {
        // Cache dashboard data for 10 minutes
        return Cache::remember("dashboard_data_{$user->id}", 600, function () use ($user): array {
            $latestMeasurement = $user->bodyMeasurements()->latest('measured_at')->first();

            $weeklyStats = $this->statsService->getWeeklyVolumeComparison($user);
            $weeklyTrend = $this->statsService->getWeeklyVolumeTrend($user);
            $volumeTrend = $this->statsService->getDailyVolumeTrend($user, 7);
            $durationDistribution = $this->statsService->getDurationDistribution($user);

            return [
                'workoutsCount' => $user->workouts()->count(),
                'thisWeekCount' => $this->getThisWeekCount($user),
                'latestWeight' => $latestMeasurement?->weight,
                'recentWorkouts' => $this->getRecentWorkouts($user),
                'recentPRs' => $this->getRecentPRs($user),
                'activeGoals' => $this->getActiveGoals($user),
                'weeklyVolume' => $weeklyStats['current_week_volume'],
                'volumeChange' => $weeklyStats['percentage'],
                'weeklyVolumeTrend' => $weeklyTrend,
                'volumeTrend' => $volumeTrend,
                'durationDistribution' => $durationDistribution,
            ];
        });
    }

    private function getThisWeekCount(User $user): int
    {
        return $user->workouts()
            ->where('started_at', '>=', now()->startOfWeek())
            ->count();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\PersonalRecord> */
    private function getRecentPRs(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $user->personalRecords()
            ->with('exercise')
            ->latest('achieved_at')
            ->take(2)
            ->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Goal> */
    private function getActiveGoals(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $user->goals()
            ->whereNull('completed_at')
            ->latest()
            ->take(2)
            ->get()
            ->append(['progress', 'unit']);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Workout> */
    private function getRecentWorkouts(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $user->workouts()
            // Bolt: Optimization - use withCount instead of with to avoid over-fetching related records.
            // Dashboard only needs the count for the icon logic.
            ->withCount('workoutLines')
            ->latest('started_at')
            ->limit(3)
            ->get();
    }
}
