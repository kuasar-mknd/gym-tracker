<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\User;
use App\Models\Workout;
use App\Services\StatsService;
use Illuminate\Support\Carbon;
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
        return [
            'workouts' => $this->getWorkouts($user),
            'monthlyFrequency' => $this->getMonthlyFrequency($user),
            'durationHistory' => $this->statsService->getDurationHistory(
                $user,
                20
            ),
            'volumeHistory' => $this->statsService->getVolumeHistory(
                $user,
                20
            ),
            'monthlyVolume' => $this->statsService->getMonthlyVolumeHistory(
                $user,
                6
            ),
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

        // 1. Fetch Data (Simple Query)
        $rawWorkouts = Workout::query()
            ->select('started_at')
            ->where('user_id', $user->id)
            ->where('started_at', '>=', $startDate)
            ->orderBy('started_at')
            ->toBase() // Keep performance optimization
            ->get();

        // 2. Group and Map
        return $rawWorkouts->groupBy(
            fn (object $row): string => substr((string) $row->started_at, 0, 7)
        )
            ->map(fn ($rows, string $month): array => [
                'month' => ($date = Carbon::createFromFormat('Y-m', $month))
                    instanceof Carbon
                    ? $date->format('M')
                    : '',
                'count' => count($rows),
            ])->values();
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
