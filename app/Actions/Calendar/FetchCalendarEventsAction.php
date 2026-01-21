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
     * @return array{workouts: \Illuminate\Support\Collection<int, mixed>, journals: \Illuminate\Support\Collection<int, mixed>}
     */
    public function execute(User $user, int $year, int $month): array
    {
        $date = Carbon::createFromDate($year, $month, 1);
        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth = $date->copy()->endOfMonth();

        return [
            'workouts' => $this->getWorkouts($user, $startOfMonth, $endOfMonth),
            'journals' => $this->getJournals($user, $startOfMonth, $endOfMonth),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, mixed> */
    private function getWorkouts(User $user, Carbon $start, Carbon $end): \Illuminate\Support\Collection
    {
        return Workout::where('user_id', $user->id)
            ->whereBetween('started_at', [$start, $end])
            ->with(['workoutLines.exercise'])
            ->get()
            ->map(fn ($workout) => [
                'id' => $workout->id,
                'name' => $workout->name ?? 'Séance',
                'date' => $workout->started_at->toDateString(),
                'started_at' => $workout->started_at->toIso8601String(),
                'exercises_count' => $workout->workoutLines->count(),
                'preview_exercises' => $workout->workoutLines->take(3)->map(fn ($line) => $line->exercise->name)->toArray(),
            ]);
    }

    /** @return \Illuminate\Support\Collection<int, mixed> */
    private function getJournals(User $user, Carbon $start, Carbon $end): \Illuminate\Support\Collection
    {
        return DailyJournal::where('user_id', $user->id)
            ->whereBetween('date', [$start, $end])
            ->get()
            ->map(fn ($journal) => [
                'id' => $journal->id,
                'date' => $journal->date->toDateString(),
                'mood_score' => $journal->mood_score,
                'has_note' => (bool) ($journal->content ?? false),
            ]);
    }
}
