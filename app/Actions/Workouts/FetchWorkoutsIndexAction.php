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
     *     monthlyFrequency: Collection<int, array{month: string, count: int}>,
     *     durationHistory: array<int, array{
     *         date: string,
     *         duration: int,
     *         name: string
     *     }>,
     *     volumeHistory: array<int, array{
     *         date: string,
     *         volume: float,
     *         name: string
     *     }>,
     *     monthlyVolume: array<int, array{
     *         month: string,
     *         volume: float
     *     }>
     * }
     */
    public function execute(User $user): array
    {
        /** @var array<int, array{date: string, duration: int, name: string}> $durationHistory */
        $durationHistory = $this->statsService->getDurationHistory($user, 20);

        /** @var array<int, array{date: string, volume: float, name: string}> $volumeHistory */
        $volumeHistory = $this->statsService->getVolumeHistory($user, 20);

        /** @var array<int, array{month: string, volume: float}> $monthlyVolume */
        $monthlyVolume = $this->statsService->getMonthlyVolumeHistory($user, 6);

        return [
            'workouts' => $this->getWorkouts($user),
            'totalExercises' => $user->workoutLines()->count(),
            'monthlyFrequency' => $this->getMonthlyFrequency($user),
            'durationHistory' => $durationHistory,
            'volumeHistory' => $volumeHistory,
            'monthlyVolume' => $monthlyVolume,
        ];
    }

    /**
     * @return Collection<int, array{month: string, count: int}>
     */
    protected function getMonthlyFrequency(
        User $user
    ): Collection {
        return Cache::remember(
            "stats.monthly_frequency.{$user->id}",
            now()->addHour(),
            fn (): Collection => $this->calculateMonthlyFrequency($user)
        );
    }

    /**
     * @return Collection<
     *     int,
     *     array{month: string, count: int}
     * >
     */
    private function calculateMonthlyFrequency(
        User $user
    ): Collection {
        $startDate = now()->subMonths(5)->startOfMonth();

        // ⚡ Bolt Optimization: Group and count directly in SQL to reduce memory usage and CPU cycles in PHP.
        $results = Workout::query()
            ->where('user_id', $user->id)
            ->where('started_at', '>=', $startDate)
            ->selectRaw("DATE_FORMAT(started_at, '%Y-%m') as month, COUNT(*) as count")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        return collect(range(0, 5))->map(function (int $i) use ($results): array {
            $date = now()->subMonths(5 - $i);
            $monthKey = $date->format('Y-m');
            $data = $results->get($monthKey);

            return [
                'month' => $date->translatedFormat('M'),
                'count' => $data ? (int) $data->count : 0,
            ];
        });
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
