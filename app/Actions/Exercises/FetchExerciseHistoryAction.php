<?php

namespace App\Actions\Exercises;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Collection;

class FetchExerciseHistoryAction
{
    /**
     * @return Collection<int, array{id: int, workout_id: int, workout_name: string, formatted_date: string, best_1rm: float, sets: array<int, array<string, mixed>>}>
     */
    public function execute(User $user, Exercise $exercise): Collection
    {
        /** @var Collection<int, Workout> $workouts */
        $workouts = Workout::where('user_id', $user->id)
            ->whereHas('workoutLines', function ($query) use ($exercise) {
                $query->where('exercise_id', $exercise->id);
            })
            ->with(['workoutLines' => function ($query) use ($exercise) {
                $query->where('exercise_id', $exercise->id)->with('sets');
            }])
            ->orderBy('started_at', 'desc')
            ->get();

        return $workouts->map(function (Workout $workout) {
            /** @var \App\Models\WorkoutLine $line */
            $line = $workout->workoutLines->first();

            /** @var array<int, array<string, mixed>> $setsData */
            $setsData = $line->sets->map(function ($set) {
                // Epley formula: w * (1 + r/30)
                $oneRm = ($set->weight ?? 0) * (1 + ($set->reps ?? 0) / 30);
                return [
                    'weight' => $set->weight,
                    'reps' => $set->reps,
                    '1rm' => $oneRm,
                ];
            })->toArray();

            /** @var float|null $best1rm */
            $best1rm = collect($setsData)->max('1rm');

            return [
                'id' => $workout->id,
                'workout_id' => $workout->id,
                'workout_name' => $workout->name ?? 'Séance',
                'formatted_date' => $workout->started_at->format('d/m/Y'),
                'best_1rm' => $best1rm ?? 0.0,
                'sets' => $setsData,
            ];
        });
    }
}
