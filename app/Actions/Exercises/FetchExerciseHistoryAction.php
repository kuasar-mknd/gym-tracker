<?php

namespace App\Actions\Exercises;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Collection;

class FetchExerciseHistoryAction
{
    public function execute(User $user, Exercise $exercise): Collection
    {
        $workouts = Workout::where('user_id', $user->id)
            ->whereHas('workoutLines', function ($query) use ($exercise) {
                $query->where('exercise_id', $exercise->id);
            })
            ->with(['workoutLines' => function ($query) use ($exercise) {
                $query->where('exercise_id', $exercise->id)->with('sets');
            }])
            ->orderBy('started_at', 'desc')
            ->get();

        return $workouts->map(function ($workout) {
            $line = $workout->workoutLines->first();
            $sets = $line->sets->map(function ($set) {
                // Epley formula: w * (1 + r/30)
                $oneRm = ($set->weight ?? 0) * (1 + ($set->reps ?? 0) / 30);

                return [
                    'weight' => $set->weight,
                    'reps' => $set->reps,
                    '1rm' => $oneRm,
                ];
            });

            return [
                'id' => $workout->id,
                'workout_id' => $workout->id,
                'workout_name' => $workout->name ?? 'Séance',
                'formatted_date' => $workout->started_at ? $workout->started_at->format('d/m/Y') : '',
                'best_1rm' => $sets->max('1rm') ?? 0,
                'sets' => $sets->toArray(),
            ];
        });
    }
}
