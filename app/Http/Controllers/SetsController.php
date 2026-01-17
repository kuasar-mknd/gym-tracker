<?php

namespace App\Http\Controllers;

use App\Models\Set;
use App\Models\WorkoutLine;

class SetsController extends Controller
{
    public function __construct(
        protected \App\Services\PersonalRecordService $prService,
        protected \App\Services\StatsService $statsService
    ) {}

    public function store(\App\Http\Requests\SetStoreRequest $request, WorkoutLine $workoutLine): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', [Set::class, $workoutLine]);

        $set = $workoutLine->sets()->create($request->validated());
        $this->prService->syncSetPRs($set);
        $this->statsService->clearUserStatsCache(auth()->user());

        return back();
    }

    public function update(\App\Http\Requests\SetUpdateRequest $request, Set $set): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $set);

        $set->update($request->validated());
        $this->prService->syncSetPRs($set);
        $this->statsService->clearUserStatsCache(auth()->user());

        return back();
    }

    public function destroy(Set $set): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $set);

        $user = auth()->user();
        $set->delete();
        $this->statsService->clearUserStatsCache($user);

        return back();
    }
}
