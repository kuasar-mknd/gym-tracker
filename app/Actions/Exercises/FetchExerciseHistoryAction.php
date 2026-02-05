<?php

declare(strict_types=1);

namespace App\Actions\Exercises;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Action to fetch exercise history.
 */
class FetchExerciseHistoryAction
{
    /**
     * Execute the action.
     *
     * @param  \App\Models\User  $user  The user to fetch history for.
     * @param  \App\Models\Exercise  $exercise  The exercise to fetch history for.
     * @return array<int, array{id: int, date: string, volume: float, max_weight: float, sets_count: int, reps_count: int}> The exercise history.
     */
    public function execute(User $user, Exercise $exercise): array
    {
        return DB::table('workouts')
            ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->where('workouts.user_id', $user->id)
            ->where('workout_lines.exercise_id', $exercise->id)
            ->whereNotNull('workouts.ended_at')
            ->select(
                'workouts.id',
                'workouts.started_at as date',
                DB::raw('SUM(sets.weight * sets.reps) as volume'),
                DB::raw('MAX(sets.weight) as max_weight'),
                DB::raw('COUNT(sets.id) as sets_count'),
                DB::raw('SUM(sets.reps) as reps_count')
            )
            ->groupBy('workouts.id', 'workouts.started_at')
            ->orderBy('workouts.started_at', 'desc')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => $row->id,
                    'date' => $row->date,
                    'volume' => (float) $row->volume,
                    'max_weight' => (float) $row->max_weight,
                    'sets_count' => (int) $row->sets_count,
                    'reps_count' => (int) $row->reps_count,
                ];
            })
            ->toArray();
    }
}
