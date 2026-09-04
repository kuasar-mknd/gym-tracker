<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\WorkoutResource;
use App\Models\Workout;

/**
 * Réordonner les exercices d'une séance : la seule écriture de séance que
 * la page fait en direct, le reste passe par les routes web.
 */
class WorkoutController extends Controller
{
    public function reorderLines(
        \App\Http\Requests\Api\WorkoutLineOrderRequest $request,
        Workout $workout,
        \App\Actions\Workouts\ReorderAction $reordonner
    ): WorkoutResource {
        $this->authorize('update', $workout);

        $reordonner->execute($workout->workoutLines(), (array) $request->validated('lines'), 'lines');

        return new WorkoutResource($workout->load(['workoutLines.exercise', 'workoutLines.sets']));
    }
}
