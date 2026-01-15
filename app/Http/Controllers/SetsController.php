<?php

namespace App\Http\Controllers;

use App\Models\Set;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class SetsController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected \App\Services\PersonalRecordService $prService) {}

    public function store(\App\Http\Requests\SetStoreRequest $request, WorkoutLine $workoutLine): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', [Set::class, $workoutLine]);

        $set = $workoutLine->sets()->create($request->validated());
        $this->prService->syncSetPRs($set);

        return back();
    }

    public function update(\App\Http\Requests\SetUpdateRequest $request, Set $set): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $set);

        $set->update($request->validated());
        $this->prService->syncSetPRs($set);

        return back();
    }

    public function destroy(Set $set): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $set);

        $set->delete();

        return back();
    }
}
