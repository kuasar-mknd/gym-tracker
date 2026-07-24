<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Workout;
use App\Models\WorkoutLine;

/**
 * Controller for managing Workout Lines.
 *
 * This controller handles adding exercises (WorkoutLines) to an existing Workout
 * and removing them. It ensures proper ordering when adding new lines.
 */
class WorkoutLineController extends Controller
{
    /**
     * Store a newly created workout line in storage.
     *
     * Validates the request, authorizes the user to add lines to the given workout,
     * calculates the order for the new line, and persists it.
     *
     * @param  \App\Http\Requests\WorkoutLineStoreRequest  $request  The validated request containing workout line details (e.g., exercise_id).
     * @param  \App\Models\Workout  $workout  The workout to which the line is being added.
     * @return \Illuminate\Http\RedirectResponse Redirects to the workout's show page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to create a line for the workout.
     */
    public function store(\App\Http\Requests\WorkoutLineStoreRequest $request, Workout $workout): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', [WorkoutLine::class, $workout]);

        $order = $workout->workoutLines()->count();

        $workout->workoutLines()->create(array_merge(
            $request->validated(),
            ['order' => $order]
        ));

        return redirect()->route('workouts.show', $workout);
    }

    /**
     * Remove the specified workout line from storage.
     *
     * Deletes the specified workout line after verifying the user is authorized
     * to perform the action.
     *
     * @param  \App\Models\WorkoutLine  $workoutLine  The workout line to delete.
     * @return \Illuminate\Http\RedirectResponse Redirects back to the previous page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete the line.
     */
    public function destroy(\App\Models\WorkoutLine $workoutLine): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $workoutLine);

        $workoutLine->delete();

        return back();
    }
}
