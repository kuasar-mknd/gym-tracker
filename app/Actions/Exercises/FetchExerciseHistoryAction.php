<?php

declare(strict_types=1);

namespace App\Actions\Exercises;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;

class FetchExerciseHistoryAction
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(User $user, Exercise $exercise): array
    {
        // Fetch workouts containing this exercise
        $workouts = Workout::query()
            ->where('user_id', $user->id)
            ->whereHas('workoutLines', function ($query) use ($exercise): void {
                $query->where('exercise_id', $exercise->id);
            })
            ->with(['workoutLines' => function ($query) use ($exercise): void {
                $query->where('exercise_id', $exercise->id)
                    ->with('sets');
            }])
            ->orderByDesc('started_at')
            ->get();

        /** @var array<int, array<string, mixed>> $history */
        $history = $workouts->map(function (Workout $workout): array {
            $line = $workout->workoutLines->first();
            $sets = $line ? $line->sets->map(fn ($set): array => [
                'weight' => $set->weight,
                'reps' => $set->reps,
                '1rm' => $set->weight * (1 + $set->reps / 30.0),
            ]) : collect();

            $best1rm = $sets->max('1rm') ?? 0;

            return [
                'id' => $workout->id,
                'workout_id' => $workout->id,
                'workout_name' => $workout->name,
                'formatted_date' => $workout->started_at->translatedFormat('D d M'),
                'best_1rm' => $best1rm,
                'sets' => $sets->toArray(),
            ];
        })->toArray();

        return $history;
    }
}
