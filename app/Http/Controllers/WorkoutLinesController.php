<?php

namespace App\Http\Controllers;

use App\Models\Workout;

class WorkoutLinesController extends Controller
{
    public function store(\App\Http\Requests\WorkoutLineStoreRequest $request, Workout $workout): \Illuminate\Http\RedirectResponse
    {
        abort_if($workout->user_id !== auth()->id(), 403);

        $order = $workout->workoutLines()->count();

        $workout->workoutLines()->create(array_merge(
            $request->validated(),
            ['order' => $order]
        ));

        return back();
    }

    public function destroy(\App\Models\WorkoutLine $workoutLine): \Illuminate\Http\RedirectResponse
    {
        abort_if($workoutLine->workout->user_id !== auth()->id(), 403);

        $workoutLine->delete();

        return back();
    }
}
