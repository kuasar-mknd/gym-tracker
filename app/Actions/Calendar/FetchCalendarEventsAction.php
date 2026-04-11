<?php

declare(strict_types=1);

namespace App\Actions\Calendar;

use App\Models\DailyJournal;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;

final class FetchCalendarEventsAction
{
    /**
     * Fetch calendar events (workouts and journals) for a given user and month.
     *
     * @param  User  $user  The user to fetch events for.
     * @param  int  $year  The year of the events.
     * @param  int  $month  The month of the events.
     * @return array{
     *     workouts: \Illuminate\Support\Collection<int, array{id: int, name: string, date: string, started_at: string, exercises_count: int, preview_exercises: array<int, string>}>,
     *     journals: \Illuminate\Support\Collection<int, array{id: int, date: string, mood_score: int|null, has_note: bool}>
     * }
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

    /** @return \Illuminate\Support\Collection<int, array{id: int, name: string, date: string, started_at: string, exercises_count: int, preview_exercises: array<int, string>}> */
    private function getWorkouts(User $user, Carbon $start, Carbon $end): \Illuminate\Support\Collection
    {
        // ⚡ Bolt: PERFORMANCE OPTIMIZATION
        // Use toBase() to avoid hydrating Eloquent models and Carbon objects.
        // This significantly reduces memory usage and execution time for large datasets.
        $workouts = Workout::query()
            ->toBase()
            ->select(['id', 'name', 'started_at'])
            ->selectSub(
                \App\Models\WorkoutLine::query()
                    ->whereColumn('workout_id', 'workouts.id')
                    ->selectRaw('count(*)'),
                'exercises_count'
            )
            ->where('user_id', $user->id)
            ->whereBetween('started_at', [$start, $end])
            ->get();

        if ($workouts->isEmpty()) {
            return collect();
        }

        // Batch fetch up to 3 exercise names per workout for the preview
        $workoutIds = $workouts->pluck('id')->toArray();

        /** @var array<int, array<int, string>> $previews */
        $previews = \Illuminate\Support\Facades\DB::table('workout_lines')
            ->join('exercises', 'workout_lines.exercise_id', '=', 'exercises.id')
            ->whereIn('workout_lines.workout_id', $workoutIds)
            ->select('workout_lines.workout_id', 'exercises.name')
            ->orderBy('workout_lines.workout_id')
            ->orderBy('workout_lines.order')
            ->get()
            ->groupBy('workout_id')
            ->map(fn (\Illuminate\Support\Collection $lines) => $lines->take(3)->pluck('name')->toArray())
            ->toArray();

        return $workouts->map(function (object $workout) use ($previews): array {
            $startedAt = (string) $workout->started_at;
            $timestamp = strtotime($startedAt);

            return [
                'id' => (int) $workout->id,
                'name' => (string) ($workout->name ?? 'Séance'),
                'date' => substr($startedAt, 0, 10),
                'started_at' => $timestamp !== false ? date('c', $timestamp) : $startedAt,
                'exercises_count' => (int) ($workout->exercises_count ?? 0),
                'preview_exercises' => $previews[$workout->id] ?? [],
            ];
        });
    }

    /** @return \Illuminate\Support\Collection<int, array{id: int, date: string, mood_score: int|null, has_note: bool}> */
    private function getJournals(User $user, Carbon $start, Carbon $end): \Illuminate\Support\Collection
    {
        // ⚡ Bolt: PERFORMANCE OPTIMIZATION
        // Use toBase() to avoid hydrating Eloquent models and Carbon objects.
        // This significantly reduces memory usage and execution time for large datasets.
        return DailyJournal::query()
            ->toBase()
            ->select(['id', 'date', 'mood_score', 'content'])
            ->where('user_id', $user->id)
            ->whereBetween('date', [$start, $end])
            ->get()
            ->map(fn (object $journal): array => [
                'id' => (int) $journal->id,
                'date' => is_string($journal->date) ? substr($journal->date, 0, 10) : (string) $journal->date,
                'mood_score' => isset($journal->mood_score) ? (int) $journal->mood_score : null,
                'has_note' => ! empty($journal->content),
            ]);
    }
}
