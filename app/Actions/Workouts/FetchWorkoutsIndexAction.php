<?php

namespace App\Actions\Workouts;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;

class FetchWorkoutsIndexAction
{
    /**
     * Fetch workouts and related statistics for the index page.
     *
     * @return array<string, mixed>
     */
    public function execute(User $user): array
    {
        // Get last 6 months frequency
        // Optimized: Use toBase() to skip model hydration and fetch only needed column
        $monthlyFrequency = Workout::where('user_id', $user->id)
            ->where('started_at', '>=', now()->subMonths(5)->startOfMonth())
            ->orderBy('started_at')
            ->toBase()
            ->get(['started_at'])
            ->groupBy(function ($row) {
                // Handle both string (from DB) and Carbon (if ever hydrated, though toBase prevents it)
                // With toBase(), it's a string 'YYYY-MM-DD HH:MM:SS'
                return substr($row->started_at, 0, 7); // 'YYYY-MM'
            })
            ->map(fn ($rows, $month) => [
                'month' => Carbon::createFromFormat('Y-m', $month)->format('M'),
                'count' => $rows->count(),
            ])
            ->values();

        // Get duration history for the last 20 workouts
        $durationHistory = Workout::select('name', 'started_at', 'ended_at')
            ->where('user_id', $user->id)
            ->whereNotNull('ended_at')
            ->latest('started_at')
            ->take(20)
            ->get()
            ->map(function ($workout) {
                return [
                    'date' => $workout->started_at->format('d/m'),
                    'duration' => $workout->ended_at->diffInMinutes($workout->started_at),
                    'name' => $workout->name,
                ];
            })
            ->reverse()
            ->values();

        // NITRO FIX: Paginate workouts instead of loading all
        $workouts = Workout::with(['workoutLines.exercise', 'workoutLines.sets'])
            ->where('user_id', $user->id)
            ->latest('started_at')
            ->paginate(20);

        return [
            'workouts' => $workouts,
            'monthlyFrequency' => $monthlyFrequency,
            'durationHistory' => $durationHistory,
        ];
    }
}
