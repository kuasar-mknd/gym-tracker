<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\StatsService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class WorkoutLinesController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected StatsService $statsService)
    {
    }

    public function store(\App\Http\Requests\WorkoutLineStoreRequest $request, Workout $workout): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', [WorkoutLine::class, $workout]);

        $order = $workout->workoutLines()->count();

        $workout->workoutLines()->create(array_merge(
            $request->validated(),
            ['order' => $order]
        ));

        // Adding a line doesn't change totals until sets are added, so no cache clear needed here.
        // The dashboard list view counts workoutLines, so we technically SHOULD clear dashboard.
        // "getRecentWorkouts" uses "withCount('workoutLines')".
        // So yes, adding a line changes the displayed count on the dashboard.

        $this->statsService->clearDashboardStats($workout->user);

        return back();
    }

    public function destroy(\App\Models\WorkoutLine $workoutLine): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $workoutLine);

        /** @var \App\Models\User $user */
        $user = $this->user();

        $workoutLine->delete();

        // Deleting a line deletes its sets, which affects volume stats.
        // It also affects the dashboard count of lines.
        $this->statsService->clearWorkoutVolumeStats($user);
        $this->statsService->clearDashboardStats($user);

        return back();
    }
}
