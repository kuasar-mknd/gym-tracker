<?php

namespace App\Actions\Dashboard;

use App\Models\User;
use App\Services\StatsService;
use Illuminate\Support\Facades\Cache;

class FetchDashboardDataAction
{
    public function __construct(
        protected StatsService $statsService
    ) {}

    /**
     * Fetch dashboard data for the given user.
     */
    public function execute(User $user): array
    {
        // Cache dashboard data for 10 minutes
        return Cache::remember("dashboard_data_{$user->id}", 600, function () use ($user) {
            $workoutsCount = $user->workouts()->count();

            $weeklyStats = $this->statsService->getWeeklyVolumeComparison($user);
            $weeklyTrend = $this->statsService->getWeeklyVolumeTrend($user);

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

            return [
                'workoutsCount' => $workoutsCount,
                'thisWeekCount' => $thisWeekCount,
                'latestWeight' => $latestMeasurement?->weight,
                'recentWorkouts' => $recentWorkouts,
                'recentPRs' => $recentPRs,
                'activeGoals' => $activeGoals,
                'weeklyVolume' => $weeklyStats['current_week_volume'],
                'volumeChange' => $weeklyStats['percentage'],
                'weeklyVolumeTrend' => $weeklyTrend,
            ];
        });
    }
}
