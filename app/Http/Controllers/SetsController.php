<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SetStoreRequest;
use App\Http\Requests\SetUpdateRequest;
use App\Models\Set;
use App\Models\WorkoutLine;
use App\Services\StatsService;
use Illuminate\Http\RedirectResponse;

/**
 * Controller for managing Workout Sets.
 */
class SetsController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected StatsService $statsService
    ) {
    }

    /**
     * Store a newly created set in storage.
     */
    public function store(SetStoreRequest $request, WorkoutLine $workoutLine): RedirectResponse
    {
        $this->authorize('create', [Set::class, $workoutLine]);

        $workoutLine->sets()->create($request->validated());

        /** @var \App\Models\User $user */
        $user = $this->user();
        $this->statsService->clearWorkoutRelatedStats($user);

        return back();
    }

    /**
     * Update the specified set in storage.
     */
    public function update(SetUpdateRequest $request, Set $set): RedirectResponse
    {
        $this->authorize('update', $set);

        $set->update($request->validated());

        /** @var \App\Models\User $user */
        $user = $this->user();
        $this->statsService->clearWorkoutRelatedStats($user);

        return back();
    }

    /**
     * Remove the specified set from storage.
     */
    public function destroy(Set $set): RedirectResponse
    {
        $this->authorize('delete', $set);

        $user = $this->user();
        $set->delete();
        $this->statsService->clearWorkoutRelatedStats($user);

        return back();
    }
}
