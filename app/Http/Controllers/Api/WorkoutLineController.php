<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Workouts\CreateWorkoutLineAction;
use App\Http\Requests\Api\WorkoutLineStoreRequest;
use App\Http\Requests\Api\WorkoutLineUpdateRequest;
use App\Http\Resources\WorkoutLineResource;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Controller for managing Workout Lines via API.
 *
 * This controller handles CRUD operations for workout lines, which represent
 * an exercise performed within a specific workout, containing multiple sets.
 */
class WorkoutLineController extends Controller
{
    /**
     * Display a paginated listing of the user's workout lines.
     *
     * Supports filtering by workout_id.
     *
     * @param  \Illuminate\Http\Request  $request  The incoming HTTP request.
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection A collection of workout line resources.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to view workout lines.
     */
    public function index(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', WorkoutLine::class);

        $lines = QueryBuilder::for(WorkoutLine::class)
            ->allowedFilters(['workout_id'])
            // Bolt: Optimize belongsTo filtering with INNER JOIN
            ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
            ->where('workouts.user_id', $this->user()->id)
            ->select('workout_lines.*')
            ->paginate();

        return WorkoutLineResource::collection($lines);
    }

    /**
     * Store a newly created workout line in storage.
     *
     * Validates the request, ensures the workout exists and belongs to the user,
     * and delegates creation to the CreateWorkoutLineAction. Recommended values
     * are automatically appended to the response.
     *
     * @param  \App\Http\Requests\Api\WorkoutLineStoreRequest  $request  The validated store request containing exercise_id, workout_id, and order.
     * @param  \App\Actions\Workouts\CreateWorkoutLineAction  $action  The action class responsible for creating the line.
     * @return \App\Http\Resources\WorkoutLineResource The newly created workout line resource.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the referenced workout does not exist.
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to add a line to the workout.
     */
    public function store(WorkoutLineStoreRequest $request, CreateWorkoutLineAction $action): WorkoutLineResource
    {
        $validated = $request->validated();

        /** @var \App\Models\Workout $workout */
        $workout = Workout::findOrFail($validated['workout_id']);

        $this->authorize('create', [WorkoutLine::class, $workout]);

        $workoutLine = $action->execute($workout, $validated);

        $workoutLine->load(['exercise', 'sets']);

        // ⚡ Perf: Accessor uses cache automatically
        $workoutLine->append('recommended_values');

        return new WorkoutLineResource($workoutLine);
    }

    /**
     * Display the specified workout line.
     *
     * Automatically appends recommended values based on user's past performance.
     *
     * @param  \App\Models\WorkoutLine  $workoutLine  The workout line to display.
     * @return \App\Http\Resources\WorkoutLineResource The requested workout line resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to view the workout line.
     */
    public function show(WorkoutLine $workoutLine): WorkoutLineResource
    {
        $this->authorize('view', $workoutLine);

        // ⚡ Perf: Accessor uses cache automatically
        $workoutLine->append('recommended_values');

        return new WorkoutLineResource($workoutLine);
    }

    /**
     * Update the specified workout line in storage.
     *
     * Allows updating the exercise or the order of the line. Recommended values
     * are automatically appended to the response.
     *
     * @param  \App\Http\Requests\Api\WorkoutLineUpdateRequest  $request  The validated update request.
     * @param  \App\Models\WorkoutLine  $workoutLine  The workout line to update.
     * @return \App\Http\Resources\WorkoutLineResource The updated workout line resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update the workout line.
     */
    public function update(WorkoutLineUpdateRequest $request, WorkoutLine $workoutLine): WorkoutLineResource
    {
        $this->authorize('update', $workoutLine);

        $workoutLine->update($request->validated());

        // ⚡ Perf: Accessor uses cache automatically
        $workoutLine->append('recommended_values');

        return new WorkoutLineResource($workoutLine);
    }

    /**
     * Remove the specified workout line from storage.
     *
     * @param  \App\Models\WorkoutLine  $workoutLine  The workout line to delete.
     * @return \Illuminate\Http\Response A 204 No Content response.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete the workout line.
     */
    public function destroy(WorkoutLine $workoutLine): Response
    {
        $this->authorize('delete', $workoutLine);

        $workoutLine->delete();

        return response()->noContent();
    }
}
