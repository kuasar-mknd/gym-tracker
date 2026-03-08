<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;

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

        // ⚡ Bolt Optimization: Explicitly append recommended_values for the active workout view.
        // This ensures the UX is preserved while avoiding N+1 queries on index pages.
        $workout->workoutLines->each->append('recommended_values');

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
