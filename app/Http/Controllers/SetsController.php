<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Workouts\CreateSetAction;
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
        protected StatsService $statsService,
        protected CreateSetAction $createSetAction
    ) {
    }

    /**
     * Store a newly created set in storage.
     */
    public function store(SetStoreRequest $request, WorkoutLine $workoutLine): RedirectResponse
    {
        $data = $request->validated();
        $data['workout_line_id'] = $workoutLine->id;

        $this->createSetAction->execute($this->user(), $data);

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
