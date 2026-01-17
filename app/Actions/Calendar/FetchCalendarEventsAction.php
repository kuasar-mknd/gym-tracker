<?php

namespace App\Actions\Calendar;

use App\Models\DailyJournal;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;

class FetchCalendarEventsAction
{
    /**
     * Fetch calendar events (workouts and journals) for a given user and month.
     *
     * @param  User  $user  The user to fetch events for.
     * @param  int  $year  The year of the events.
     * @param  int  $month  The month of the events.
     * @return array An array containing 'workouts' and 'journals' collections.
     */
    public function execute(User $user, int $year, int $month): array
    {
        $date = Carbon::createFromDate($year, $month, 1);
        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth = $date->copy()->endOfMonth();

        // Fetch Workouts
        $workouts = Workout::where('user_id', $user->id)
            ->whereBetween('started_at', [$startOfMonth, $endOfMonth])
            ->with(['workoutLines.exercise']) // Eager load for quick preview
            ->get()
            ->map(function ($workout) {
                return [
                    'id' => $workout->id,
                    'name' => $workout->name ?? 'Séance',
                    'date' => $workout->started_at->toDateString(),
                    'started_at' => $workout->started_at->toIso8601String(),
                    'exercises_count' => $workout->workoutLines->count(),
                    'preview_exercises' => $workout->workoutLines->take(3)->map(fn ($line) => $line->exercise->name)->toArray(),
                ];
            });

        // Fetch Journals
        $journals = DailyJournal::where('user_id', $user->id)
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->get()
            ->map(function ($journal) {
                return [
                    'id' => $journal->id,
                    'date' => $journal->date->toDateString(),
                    'mood_score' => $journal->mood_score,
                    'has_note' => ! empty($journal->content),
                ];
            });

        return [
            'workouts' => $workouts,
            'journals' => $journals,
        ];
    }
}
