<?php

declare(strict_types=1);

namespace App\Actions\Exercises;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class FetchExerciseHistoryAction
{
    /**
     * @return array<int, array{date: string, weight: float, reps: int, one_rep_max: float, volume: float}>
     */
    public function execute(User $user, Exercise $exercise): array
    {
        return DB::table('sets')
            ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
            ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
            ->where('workouts.user_id', $user->id)
            ->where('workout_lines.exercise_id', $exercise->id)
            ->whereNotNull('sets.weight')
            ->whereNotNull('sets.reps')
            ->select([
                'workouts.started_at',
                'sets.weight',
                'sets.reps',
                DB::raw('(sets.weight * (1 + (sets.reps / 30))) as one_rep_max'),
                DB::raw('(sets.weight * sets.reps) as volume'),
            ])
            ->orderByDesc('workouts.started_at')
            ->limit(100)
            ->get()
            ->map(fn (object $record): array => [
                'date' => \Illuminate\Support\Carbon::parse($record->started_at)->format('d/m/Y'),
                'weight' => (float) $record->weight,
                'reps' => (int) $record->reps,
                'one_rep_max' => round((float) $record->one_rep_max, 2),
                'volume' => (float) $record->volume,
            ])
            ->toArray();
    }
}
