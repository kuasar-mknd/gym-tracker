<?php

namespace App\Http\Controllers;

use App\Models\Set;
use App\Models\WorkoutLine;

/**
 * Controller for managing Sets within a Workout.
 *
 * This controller handles the CRUD operations for sets (weight, reps, etc.)
 * belonging to a specific exercise (WorkoutLine). It interacts with:
 * - PersonalRecordService: To check and update any new PRs achieved.
 * - StatsService: To invalidate user statistics caches when data changes.
 *
 * It is primarily used by the Inertia frontend.
 */
class SetsController extends Controller
{
    /**
     * Create a new SetsController instance.
     *
     * @param  \App\Services\PersonalRecordService  $prService  Service for calculating and syncing Personal Records.
     * @param  \App\Services\StatsService  $statsService  Service for managing user statistics and caching.
     */
    public function __construct(
        protected \App\Services\PersonalRecordService $prService,
        protected \App\Services\StatsService $statsService
    ) {}

    /**
     * Store a newly created set in storage.
     *
     * Creates a new set for the specified workout line.
     * Automatically checks for new Personal Records and invalidates the user's stats cache.
     *
     * @param  \App\Http\Requests\SetStoreRequest  $request  The validated request containing set details (weight, reps, etc.).
     * @param  \App\Models\WorkoutLine  $workoutLine  The workout line (exercise) to attach the set to.
     * @return \Illuminate\Http\RedirectResponse Redirects back to the previous page (typically the workout view).
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to add sets to this workout line.
     */
    public function store(\App\Http\Requests\SetStoreRequest $request, WorkoutLine $workoutLine): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', [Set::class, $workoutLine]);

        $set = $workoutLine->sets()->create($request->validated());
        $this->prService->syncSetPRs($set);
        $this->statsService->clearUserStatsCache($this->user());

        return back();
    }

    /**
     * Update the specified set in storage.
     *
     * Updates an existing set's details.
     * Re-evaluates Personal Records based on the updated values and invalidates the user's stats cache.
     *
     * @param  \App\Http\Requests\SetUpdateRequest  $request  The validated request containing updated set details.
     * @param  \App\Models\Set  $set  The set to update.
     * @return \Illuminate\Http\RedirectResponse Redirects back to the previous page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update this set.
     */
    public function update(\App\Http\Requests\SetUpdateRequest $request, Set $set): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $set);

        $set->update($request->validated());
        $this->prService->syncSetPRs($set);
        $this->statsService->clearUserStatsCache($this->user());

        return back();
    }

    /**
     * Remove the specified set from storage.
     *
     * Deletes the set and invalidates the user's stats cache.
     * Note: Deleting a set does not currently remove associated Personal Records if it was a PR set
     * (unless handled by model observers or database cascades).
     *
     * @param  \App\Models\Set  $set  The set to delete.
     * @return \Illuminate\Http\RedirectResponse Redirects back to the previous page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete this set.
     */
    public function destroy(Set $set): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $set);

        $user = $this->user();
        $set->delete();
        $this->statsService->clearUserStatsCache($user);

        return back();
    }
}
