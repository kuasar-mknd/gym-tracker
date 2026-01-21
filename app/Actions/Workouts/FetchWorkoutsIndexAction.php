<?php

namespace App\Actions\Workouts;

use App\Models\User;
use App\Models\Workout;
use App\Services\StatsService;
use Illuminate\Support\Carbon;

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
    private function getMonthlyFrequency(User $user): \Illuminate\Support\Collection
    {
        return Workout::where('user_id', $user->id)
            ->where('started_at', '>=', now()->subMonths(5)->startOfMonth())
            ->orderBy('started_at')
            ->toBase()
            ->get(['started_at'])
            ->groupBy(fn ($row) => substr($row->started_at, 0, 7))
            ->map(fn ($rows, $month) => [
                'month' => Carbon::createFromFormat('Y-m', $month)?->format('M') ?? '',
                'count' => $rows->count(),
            ])
            ->values();
    }

    /** @return \Illuminate\Pagination\LengthAwarePaginator<\App\Models\Workout> */
    private function getWorkouts(User $user): \Illuminate\Pagination\LengthAwarePaginator
    {
        return Workout::with(['workoutLines.exercise', 'workoutLines.sets'])
            ->where('user_id', $user->id)
            ->latest('started_at')
            ->paginate(20);
    }
}
