<?php

declare(strict_types=1);

namespace App\Actions\Exercises;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FetchExerciseHistoryAction
{
    /**
     * @return Collection<int, object>
     */
    public function execute(User $user, Exercise $exercise): Collection
    {
        return DB::table('workouts')
            ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->where('workouts.user_id', $user->id)
            ->where('workout_lines.exercise_id', $exercise->id)
            ->whereNotNull('workouts.ended_at')
            ->select(
                'workouts.id',
                'workouts.name',
                'workouts.started_at',
                'sets.weight',
                'sets.reps'
            )
            ->orderByDesc('workouts.started_at')
            ->get()
            ->groupBy('id')
            ->map(function ($sets) {
                $first = $sets->first();

                return [
                    'id' => $first->id,
                    'name' => $first->name,
                    'date' => $first->started_at,
                    'sets' => $sets->map(fn ($set) => [
                        'weight' => $set->weight,
                        'reps' => $set->reps,
                    ]),
                ];
            })
            ->values();
    }
}
