<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Workout;
use App\Models\WorkoutLine;

/**
 * Controller for managing Workout Lines.
 *
 * This controller handles adding new exercise lines to an existing workout
 * and removing them. It ensures proper authorization for these actions.
 */
class WorkoutLineController extends Controller
{
    /**
     * Store a new workout line within a specific workout.
     *
     * @param  \App\Http\Requests\WorkoutLineStoreRequest  $request  The validated request containing workout line details.
     * @param  \App\Models\Workout  $workout  The workout to which the line will be added.
     * @return \Illuminate\Http\RedirectResponse Redirects back to the workout show page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to create a line for this workout.
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
     * Delete an existing workout line.
     *
     * @param  \App\Models\WorkoutLine  $workoutLine  The workout line to delete.
     * @return \Illuminate\Http\RedirectResponse Redirects back to the previous page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete this workout line.
     */
    public function destroy(\App\Models\WorkoutLine $workoutLine): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $workoutLine);

        $workoutLine->delete();

        return back();
    }
}
