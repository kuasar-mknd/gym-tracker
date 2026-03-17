<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Controller for managing Workout Lines.
 *
 * This controller handles adding exercises (lines) to an existing workout
 * and removing them. It ensures proper authorization for these actions.
 */
class WorkoutLinesController extends Controller
{
    use AuthorizesRequests;

    /**
     * Add a new exercise line to a workout.
     *
     * Validates the request, calculates the correct order for the new line,
     * and attaches it to the specified workout.
     *
     * @param  \App\Http\Requests\WorkoutLineStoreRequest  $request  The validated request containing exercise details.
     * @param  \App\Models\Workout  $workout  The workout to add the line to.
     * @return \Illuminate\Http\RedirectResponse Redirects back to the workout show page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to modify the workout.
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
     * Remove an exercise line from a workout.
     *
     * Deletes the specified workout line from the database.
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
