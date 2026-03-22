<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\User;
use App\Models\Workout;
use App\Services\StatsService;

final class FetchWorkoutsIndexAction
{
    public function __construct(protected StatsService $statsService)
    {
        // Dependency injection
    }

    /**
     * Fetch workouts and related statistics for the index page.
     *
     * @return array{
     *     workouts: \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\Workout>
     * }
     */
    public function execute(User $user): array
    {
        return [
            'workouts' => $this->getWorkouts($user),
        ];
    }

    /**
     * Get heavy chart data for deferred loading.
     *
     * @return array{
     *     monthly_frequency: array<int, array{month: string, count: int}>,
     *     monthly_volume: array<int, array{month: string, volume: float}>,
     *     duration_history: array<int, array{date: string, duration: int, name: string}>,
     *     volume_history: array<int, array{date: string, volume: float, name: string}>
     * }
     */
    public function getChartData(User $user): array
    {
        // ⚡ Bolt: PERFORMANCE OPTIMIZATION
        // Consolidate multiple service calls into two efficient grouped calls.
        $monthlyStats = $this->statsService->getMonthlyWorkoutStats($user, 6);
        $recentAnalytics = $this->statsService->getRecentWorkoutsAnalytics($user, 20);

        return [
            'monthly_frequency' => $monthlyStats['monthly_frequency'],
            'monthly_volume' => $monthlyStats['monthly_volume'],
            'duration_history' => $recentAnalytics['duration_history'],
            'volume_history' => $recentAnalytics['volume_history'],
        ];
    }

    /** @return \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\Workout> */
    private function getWorkouts(
        User $user
    ): \Illuminate\Pagination\LengthAwarePaginator {
        return Workout::with([
            'workoutLines' => function ($query): void {
                $query->with('exercise')->withCount('sets');
            },
        ])
            ->where('user_id', $user->id)
            ->latest('started_at')
            ->paginate(20);
    }
}
