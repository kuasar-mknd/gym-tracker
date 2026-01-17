<?php

namespace App\Actions\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class FetchDashboardDataAction
{
    /**
     * Fetch dashboard data for the given user.
     */
    public function __construct(protected \App\Services\StatsService $statsService) {}

    /**
     * Fetch dashboard data for the given user.
     */
    public function execute(User $user): array
    {
        // Cache dashboard data for 10 minutes
        return Cache::remember("dashboard_data_{$user->id}", 600, function () use ($user) {
            $workoutsCount = $user->workouts()->count();

            $startOfWeek = now()->startOfWeek();
            $thisWeekCount = $user->workouts()
                ->where('started_at', '>=', $startOfWeek)
                ->count();

            $latestMeasurement = $user->bodyMeasurements()->latest('measured_at')->first();

            $recentWorkouts = $user->workouts()
                ->with('workoutLines.exercise', 'workoutLines.sets')
                ->latest('started_at')
                ->limit(5)
                ->get();

            $recentPRs = $user->personalRecords()
                ->with('exercise')
                ->latest('achieved_at')
                ->take(5)
                ->get();

            $activeGoals = $user->goals()
                ->with('exercise')
                ->whereNull('completed_at')
                ->latest()
                ->take(3)
                ->get()
                ->append(['progress', 'unit']);

            $volumeTrend = $this->statsService->getDailyVolumeTrend($user, 7);
            $weeklyVolume = array_sum(array_column($volumeTrend, 'volume'));

            return [
                'workoutsCount' => $workoutsCount,
                'thisWeekCount' => $thisWeekCount,
                'latestWeight' => $latestMeasurement?->weight,
                'recentWorkouts' => $recentWorkouts,
                'recentPRs' => $recentPRs,
                'activeGoals' => $activeGoals,
                'volumeTrend' => $volumeTrend,
                'weeklyVolume' => $weeklyVolume,
            ];
        });
    }
}
