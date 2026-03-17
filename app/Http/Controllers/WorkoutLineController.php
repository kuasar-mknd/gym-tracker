<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Workout;
use App\Models\WorkoutLine;

class WorkoutLineController extends Controller
{
    public function store(\App\Http\Requests\WorkoutLineStoreRequest $request, Workout $workout): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', [WorkoutLine::class, $workout]);

        $order = $workout->workoutLines()->count();

        $workout->workoutLines()->create(array_merge(
            $request->validated(),
            ['order' => $order]
        ));

        return redirect()->route('workouts.show', $workout);
    }

    public function destroy(\App\Models\WorkoutLine $workoutLine): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $workoutLine);

        $workoutLine->delete();

        return back();
    }
}
