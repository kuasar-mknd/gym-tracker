<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\User;
use App\Models\Workout;
use App\Services\StatsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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
     *     workouts: \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\Workout>,
     *     totalExercises: int
     * }
     */
    public function execute(User $user): array
    {
        return [
            'workouts' => $this->getWorkouts($user),
            'totalExercises' => $user->workoutLines()->count(),
        ];
    }

    /**
     * Get deferred chart data.
     *
     * @return array{
     *     monthly_frequency: \Illuminate\Support\Collection<int, array{month: string, count: int}>,
     *     monthly_volume: array<int, \App\DTOs\Stats\MonthlyVolumePoint>,
     *     duration_history: array<int, \App\DTOs\Stats\DurationHistoryPoint>,
     *     volume_history: array<int, \App\DTOs\Stats\VolumeHistoryPoint>
     * }
     */
    public function getChartData(User $user): array
    {
        return [
            'monthly_frequency' => $this->statsService->getMonthlyFrequency($user),
            'monthly_volume' => $this->statsService->getMonthlyVolumeHistory($user, 6),
            'duration_history' => $this->statsService->getDurationHistory($user, 20),
            'volume_history' => $this->statsService->getVolumeHistory($user, 20),
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
