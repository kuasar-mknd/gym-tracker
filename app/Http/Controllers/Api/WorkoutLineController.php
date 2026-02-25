<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\WorkoutLineStoreRequest;
use App\Http\Requests\Api\WorkoutLineUpdateRequest;
use App\Http\Resources\WorkoutLineResource;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\QueryBuilder;

class WorkoutLineController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', WorkoutLine::class);

        $lines = QueryBuilder::for(WorkoutLine::class)
            ->allowedFilters(['workout_id'])
            ->whereHas('workout', function ($query): void {
                $query->where('user_id', $this->user()->id);
            })
            ->paginate();

        return WorkoutLineResource::collection($lines);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(WorkoutLineStoreRequest $request): WorkoutLineResource
    {
        $validated = $request->validated();

        /** @var \App\Models\Workout $workout */
        $workout = Workout::findOrFail($validated['workout_id']);

        $this->authorize('create', [WorkoutLine::class, $workout]);

        // @phpstan-ignore-next-line
        $order = $validated['order'] ?? (is_null($workout->workoutLines()->max('order')) ? 0 : $workout->workoutLines()->max('order') + 1);

        /** @var \App\Models\WorkoutLine $workoutLine */
        $workoutLine = $workout->workoutLines()->create(array_merge(
            collect($validated)->except('workout_id')->toArray(),
            ['order' => $order]
        ));

        return new WorkoutLineResource($workoutLine);
    }

    /**
     * Display the specified resource.
     */
    public function show(WorkoutLine $workoutLine): WorkoutLineResource
    {
        $this->authorize('view', $workoutLine);

        return new WorkoutLineResource($workoutLine);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(WorkoutLineUpdateRequest $request, WorkoutLine $workoutLine): WorkoutLineResource
    {
        $this->authorize('update', $workoutLine);

        $workoutLine->update($request->validated());

        return new WorkoutLineResource($workoutLine);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(WorkoutLine $workoutLine): Response
    {
        $this->authorize('delete', $workoutLine);

        $workoutLine->delete();

        return response()->noContent();
    }
}
