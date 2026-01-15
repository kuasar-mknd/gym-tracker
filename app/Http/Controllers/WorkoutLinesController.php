<?php

namespace App\Http\Controllers;

use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class WorkoutLinesController extends Controller
{
    use AuthorizesRequests;

    public function store(\App\Http\Requests\WorkoutLineStoreRequest $request, Workout $workout): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', [WorkoutLine::class, $workout]);

        $order = $workout->workoutLines()->count();

        $workout->workoutLines()->create(array_merge(
            $request->validated(),
            ['order' => $order]
        ));

        return back();
    }

    public function destroy(\App\Models\WorkoutLine $workoutLine): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $workoutLine);

        $workoutLine->delete();

        return back();
    }
}
