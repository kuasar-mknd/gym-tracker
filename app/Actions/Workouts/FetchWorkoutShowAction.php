<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

class FetchWorkoutShowAction
{
    /**
     * Prepare the data required for the workout show view.
     *
     * @return array<string, mixed>
     */
    public function execute(User $user, Workout $workout): array
    {
        $exercises = Exercise::getCachedForUser($user->id);

        $workout->load(['workoutLines.exercise', 'workoutLines.sets.personalRecord']);

        // ⚡ Perf: Batch-load recommended values in 1-2 queries instead of N+1
        WorkoutLine::batchRecommendedValues($workout->workoutLines, $user->id);

        return [
            'workout' => $workout,
            'exercises' => $exercises,
            'categories' => ['Pectoraux', 'Dos', 'Jambes', 'Épaules', 'Bras', 'Abdominaux', 'Cardio'],
            'types' => [
                ['value' => 'strength', 'label' => 'Force'],
                ['value' => 'cardio', 'label' => 'Cardio'],
                ['value' => 'timed', 'label' => 'Temps'],
            ],
        ];
    }
}
