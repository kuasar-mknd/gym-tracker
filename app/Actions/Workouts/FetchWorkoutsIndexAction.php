<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\Exercise;
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
     * Get consolidated deferred data (charts and exercises).
     * ⚡ Bolt: Reduces 2 deferred prop XHR requests to 1.
     *
     * @return array{
     *     charts: array{
     *         monthly_frequency: Collection<int, array{month: string, count: int}>,
     *         day_of_week_frequency: Collection<int, array{day: string, count: int}>,
     *         monthly_volume: array<int, \App\DTOs\Stats\MonthlyVolumePoint>,
     *         duration_history: array<int, \App\DTOs\Stats\DurationHistoryPoint>,
     *         volume_history: array<int, \App\DTOs\Stats\VolumeHistoryPoint>
     *     },
     *     exercises: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Exercise>
     * }
     */
    public function getDeferredData(User $user): array
    {
        return [
            'charts' => [
                'monthly_frequency' => $this->getMonthlyFrequency($user),
                'day_of_week_frequency' => $this->getDayOfWeekFrequency($user),
                'monthly_volume' => $this->statsService->getMonthlyVolumeHistory($user, 6),
                'duration_history' => $this->statsService->getDurationHistory($user, 20),
                'volume_history' => $this->statsService->getVolumeHistory($user, 20),
            ],
            'exercises' => Exercise::getCachedForUser($user->id),
        ];
    }

    /**
     * @return Collection<int, array{day: string, count: int}>
     */
    protected function getDayOfWeekFrequency(User $user): Collection
    {
        return Cache::remember(
            "stats.day_of_week_frequency.{$user->id}",
            now()->addHour(),
            fn (): Collection => $this->calculateDayOfWeekFrequency($user)
        );
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
        // Also uses toBase() to avoid Eloquent model hydration.
        $results = Workout::query()
            ->toBase()
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
                'count' => $data && is_numeric($data->count) ? (int) $data->count : 0,
            ];
        });
    }

    /**
     * @return Collection<int, array{day: string, count: int}>
     */
    private function calculateDayOfWeekFrequency(User $user): Collection
    {
        $results = Workout::query()
            ->toBase()
            ->where('user_id', $user->id)
            ->selectRaw('DAYOFWEEK(started_at) as day_of_week, COUNT(*) as count')
            ->groupBy('day_of_week')
            ->get()
            ->keyBy('day_of_week');

        $days = [
            2 => 'Lun',
            3 => 'Mar',
            4 => 'Mer',
            5 => 'Jeu',
            6 => 'Ven',
            7 => 'Sam',
            1 => 'Dim',
        ];

        return collect($days)->map(function (string $dayName, int $dayIndex) use ($results): array {
            $data = $results->get($dayIndex);

            return [
                'day' => $dayName,
                'count' => $data && is_numeric($data->count) ? (int) $data->count : 0,
            ];
        })->values();
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
