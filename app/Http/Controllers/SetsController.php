<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Set;
use App\Models\WorkoutLine;

/**
 * Controller for managing Sets within Workout Lines.
 *
 * This controller handles the CRUD operations for sets (weight, reps, etc.) via Inertia requests.
 * It is responsible for ensuring that Personal Records are synced and user statistics caches
 * are cleared whenever a set is created, updated, or deleted.
 */
class SetsController extends Controller
{
    /**
     * Create a new SetsController instance.
     *
     * @param  \App\Services\PersonalRecordService  $prService  Service to handle Personal Record calculations and updates.
     * @param  \App\Services\StatsService  $statsService  Service to handle user statistics and cache invalidation.
     */
    public function __construct(
        protected \App\Services\PersonalRecordService $prService,
        protected \App\Services\StatsService $statsService
    ) {
    }

    /**
     * Store a newly created set in storage.
     *
     * Creates a new set associated with the given workout line. After creation,
     * it triggers a sync of Personal Records (to check if this set is a new PR)
     * and clears the user's statistics cache to ensure dashboards reflect the new data.
     *
     * @param  \App\Http\Requests\SetStoreRequest  $request  The validated request containing set data (weight, reps, etc.).
     * @param  \App\Models\WorkoutLine  $workoutLine  The workout line the set belongs to.
     * @return \Illuminate\Http\RedirectResponse Redirects back to the previous page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to create sets for this workout line.
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
     * Updates an existing set with new data. Like creation, this triggers
     * a PR sync and statistics cache clearance.
     *
     * @param  \App\Http\Requests\SetUpdateRequest  $request  The validated request containing updated set data.
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
     * Deletes a set permanently. This action also triggers a clearance of the
     * user's statistics cache to remove the deleted set's data from aggregations.
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
