<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\WorkoutLineStoreRequest;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;

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
     * @param  WorkoutLineStoreRequest  $request  The validated request containing workout line details (e.g., exercise_id).
     * @param  Workout  $workout  The workout to which the line is being added.
     * @return RedirectResponse Redirects to the workout's show page.
     *
     * @throws AuthorizationException If the user is not authorized to create a line for the workout.
     */
    public function store(WorkoutLineStoreRequest $request, Workout $workout): RedirectResponse
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
     * @param  WorkoutLine  $workoutLine  The workout line to delete.
     * @return RedirectResponse Redirects back to the previous page.
     *
     * @throws AuthorizationException If the user is not authorized to delete the line.
     */
    public function destroy(WorkoutLine $workoutLine): RedirectResponse
    {
        $this->authorize('delete', $workoutLine);

        $workoutLine->delete();

        return back();
    }
}
