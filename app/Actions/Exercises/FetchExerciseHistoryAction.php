<?php

declare(strict_types=1);

namespace App\Actions\Exercises;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Collection;

class FetchExerciseHistoryAction
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function execute(User $user, Exercise $exercise): Collection
    {
        /** @var Collection<int, \App\Models\WorkoutLine> $workoutLines */
        $workoutLines = $user->workoutLines()
            ->with(['workout', 'sets'])
            ->where('exercise_id', $exercise->id)
            ->whereHas('workout', function ($query): void {
                $query->whereNotNull('ended_at');
            })
            ->get();

        /** @var Collection<int, array<string, mixed>> */
        return $workoutLines
            ->sortByDesc(fn (\App\Models\WorkoutLine $line) => $line->workout->ended_at)
            ->map(function (\App\Models\WorkoutLine $line): array {
                /** @var \App\Models\Workout $workout */
                $workout = $line->workout;

                return [
                    'id' => $line->id,
                    'date' => $workout->ended_at,
                    'workout_name' => $workout->name,
                    'sets' => $line->sets->map(function (\App\Models\Set $set): array {
                        return [
                            'weight' => $set->weight,
                            'reps' => $set->reps,
                            'distance' => $set->distance_km,
                            'duration' => $set->duration_seconds,
                        ];
                    }),
                ];
            })
            ->values();
    }
}
