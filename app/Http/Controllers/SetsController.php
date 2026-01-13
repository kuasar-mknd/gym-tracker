<?php

namespace App\Http\Controllers;

use App\Models\Set;
use App\Models\WorkoutLine;

class SetsController extends Controller
{
    public function __construct(protected \App\Services\PersonalRecordService $prService) {}

    public function store(\App\Http\Requests\SetStoreRequest $request, WorkoutLine $workoutLine): \Illuminate\Http\RedirectResponse
    {
        abort_if($workoutLine->workout->user_id !== auth()->id(), 403);

        $set = $workoutLine->sets()->create($request->validated());
        $this->prService->syncSetPRs($set);

        return back();
    }

    public function update(\App\Http\Requests\SetUpdateRequest $request, Set $set): \Illuminate\Http\RedirectResponse
    {
        abort_if($set->workoutLine->workout->user_id !== auth()->id(), 403);

        $set->update($request->validated());
        $this->prService->syncSetPRs($set);

        return back();
    }

    public function destroy(Set $set): \Illuminate\Http\RedirectResponse
    {
        abort_if($set->workoutLine->workout->user_id !== auth()->id(), 403);

        $set->delete();

        return back();
    }
}
