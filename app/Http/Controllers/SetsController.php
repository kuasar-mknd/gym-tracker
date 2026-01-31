<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Set;
use App\Models\WorkoutLine;

/**
 * Controller for managing Workout Sets.
 *
 * This controller handles the creation, update, and deletion of sets within a workout line.
 * It is a critical component that ensures data integrity by synchronizing Personal Records (PRs)
 * and invalidating relevant statistics caches whenever a set is modified.
 */
class SetsController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param  \App\Services\PersonalRecordService  $prService  Service for handling Personal Record calculations and updates.
     * @param  \App\Services\StatsService  $statsService  Service for managing user statistics and cache invalidation.
     */
    public function __construct(
        protected \App\Services\PersonalRecordService $prService,
        protected \App\Services\StatsService $statsService
    ) {
    }

    /**
     * Store a newly created set in storage.
     *
     * Creates a new set for the specified workout line.
     * After creation, it triggers a PR synchronization check to see if this set breaks any records.
     * It also clears the user's workout-related statistics cache to reflect the new data.
     *
     * @param  \App\Http\Requests\SetStoreRequest  $request  The validated request containing weight, reps, etc.
     * @param  \App\Models\WorkoutLine  $workoutLine  The workout line to attach the set to.
     * @return \Illuminate\Http\RedirectResponse A redirect back to the previous page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to create a set.
     */
    public function store(\App\Http\Requests\SetStoreRequest $request, WorkoutLine $workoutLine): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', [Set::class, $workoutLine]);

        $set = $workoutLine->sets()->create($request->validated());
        $this->prService->syncSetPRs($set);
        $this->statsService->clearWorkoutRelatedStats($this->user());

        return back();
    }

    /**
     * Update the specified set in storage.
     *
     * Updates the attributes of an existing set.
     * Triggers PR synchronization to check if the updated values constitute a new record.
     * Invalidates the user's workout statistics cache.
     *
     * @param  \App\Http\Requests\SetUpdateRequest  $request  The validated request containing updated fields.
     * @param  \App\Models\Set  $set  The set to update.
     * @return \Illuminate\Http\RedirectResponse A redirect back to the previous page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update this set.
     */
    public function update(\App\Http\Requests\SetUpdateRequest $request, Set $set): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $set);

        $set->update($request->validated());
        $this->prService->syncSetPRs($set);
        $this->statsService->clearWorkoutRelatedStats($this->user());

        return back();
    }

    /**
     * Remove the specified set from storage.
     *
     * Deletes the set and invalidates the user's workout statistics cache.
     * Note: Deleting a set does not currently revert PRs if this set was the record holder (pending feature).
     *
     * @param  \App\Models\Set  $set  The set to delete.
     * @return \Illuminate\Http\RedirectResponse A redirect back to the previous page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete this set.
     */
    public function destroy(Set $set): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $set);

        $user = $this->user();
        $set->delete();
        $this->statsService->clearWorkoutRelatedStats($user);

        return back();
    }
}
