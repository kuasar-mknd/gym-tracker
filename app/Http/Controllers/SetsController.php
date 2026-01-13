<?php

namespace App\Http\Controllers;

use App\Models\Set;
use App\Models\WorkoutLine;

class SetsController extends Controller
{
    public function store(\App\Http\Requests\SetStoreRequest $request, WorkoutLine $workoutLine): \Illuminate\Http\RedirectResponse
    {
        abort_if($workoutLine->workout->user_id !== auth()->id(), 403);

        $workoutLine->sets()->create([
            'weight' => $request->weight,
            'reps' => $request->reps,
            'duration_seconds' => $request->duration_seconds,
            'distance_km' => $request->distance_km,
            'is_warmup' => $request->is_warmup ?? false,
        ]);

        return back();
    }

    public function update(\App\Http\Requests\SetUpdateRequest $request, Set $set): \Illuminate\Http\RedirectResponse
    {
        abort_if($set->workoutLine->workout->user_id !== auth()->id(), 403);

        $set->update($request->validated());

        return back();
    }

    public function destroy(Set $set): \Illuminate\Http\RedirectResponse
    {
        abort_if($set->workoutLine->workout->user_id !== auth()->id(), 403);

        $set->delete();

        return back();
    }
}
