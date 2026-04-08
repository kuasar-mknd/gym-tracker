<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

/**
 * Action class responsible for preparing the data necessary to display a specific workout.
 *
 * This class fetches all relevant nested relationships (lines, sets, exercises, personal records)
 * and available reference data (exercise lists, categories) required by the frontend view.
 */
class FetchWorkoutShowAction
{
    /**
     * Prepare the data required for the workout show view.
     *
     * @param  \App\Models\User  $user  The authenticated user viewing the workout.
     * @param  \App\Models\Workout  $workout  The workout to be displayed.
     * @return array{
     *     workout: \App\Models\Workout,
     *     exercises: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Exercise>|array<int, \App\Models\Exercise>
     * }
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
        ];
    }
}
