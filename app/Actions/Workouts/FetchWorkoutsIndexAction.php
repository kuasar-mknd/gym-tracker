<?php

namespace App\Actions\Workouts;

use App\Models\User;
use App\Models\Workout;
use App\Services\StatsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class FetchWorkoutsIndexAction
{
    public function __construct(protected StatsService $statsService) {}

    /**
     * Fetch workouts and related statistics for the index page.
     *
     * @return array<string, mixed>
     */
    public function execute(User $user): array
    {
        return [
            'workouts' => $this->getWorkouts($user),
            'monthlyFrequency' => $this->getMonthlyFrequency($user),
            'durationHistory' => $this->statsService->getDurationHistory($user, 20),
            'volumeHistory' => $this->statsService->getVolumeHistory($user, 20),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, array{month: string, count: int}> */
    /** @return \Illuminate\Support\Collection<int, array{month: string, count: int}> */
    private function getMonthlyFrequency(User $user): \Illuminate\Support\Collection
    {
        return Cache::remember(
            "stats.monthly_frequency.{$user->id}",
            now()->addHour(),
            function () use ($user) {
                $startDate = now()->subMonths(5)->startOfMonth();

                // 1. Fetch Data (Simple Query)
                $rawWorkouts = Workout::query()
                    ->select('started_at')
                    ->where('user_id', $user->id)
                    ->where('started_at', '>=', $startDate)
                    ->orderBy('started_at')
                    ->toBase() // Keep performance optimization
                    ->get();

                // 2. Group Manually (Avoid nested closure scopes that confuse Rector)
                $groupedByMonth = $rawWorkouts->groupBy(fn (object $row): string => substr((string) $row->started_at, 0, 7));

                // 3. Map Manually
                return $groupedByMonth->map(function ($rows, string $month): array {
                    $date = Carbon::createFromFormat('Y-m', $month);

                    return [
                        'month' => $date instanceof Carbon ? $date->format('M') : '',
                        'count' => count($rows),
                    ];
                })->values();
            }
        );
    }

    /** @return \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\Workout> */
    private function getWorkouts(User $user): \Illuminate\Pagination\LengthAwarePaginator
    {
        return Workout::with(['workoutLines.exercise', 'workoutLines.sets'])
            ->where('user_id', $user->id)
            ->latest('started_at')
            ->paginate(20);
    }
}
