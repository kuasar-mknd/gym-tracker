<?php

declare(strict_types=1);

namespace App\Actions\Exercises;

use App\Models\Exercise;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class FetchExerciseHistoryAction
{
    /**
     * @return array<int, array{date: string, full_date: string, workout_name: string, sets: array<int, array{weight: float, reps: int, rpe: ?float}>}>
     */
    public function execute(User $user, Exercise $exercise): array
    {
        $history = DB::table('sets')
            ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
            ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
            ->where('workouts.user_id', $user->id)
            ->where('workout_lines.exercise_id', $exercise->id)
            ->whereNotNull('workouts.ended_at')
            ->select(
                'workouts.id as workout_id',
                'workouts.started_at',
                'workouts.name as workout_name',
                'sets.weight',
                'sets.reps',
                'sets.rpe'
            )
            ->orderByDesc('workouts.started_at')
            ->get();

        return $history->groupBy('workout_id')->map(function ($sets) {
            $first = $sets->first();

            if (! $first) {
                return null;
            }

            return [
                'date' => Carbon::parse($first->started_at)->format('d/m/Y'),
                'full_date' => $first->started_at,
                'workout_name' => $first->workout_name,
                'sets' => $sets->map(fn ($set) => [
                    'weight' => (float) $set->weight,
                    'reps' => (int) $set->reps,
                    'rpe' => $set->rpe ? (float) $set->rpe : null,
                ])->values()->all(),
            ];
        })->filter()->values()->all();
    }
}
