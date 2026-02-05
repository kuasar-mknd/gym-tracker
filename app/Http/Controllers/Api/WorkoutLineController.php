<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\WorkoutLineStoreRequest;
use App\Http\Requests\Api\WorkoutLineUpdateRequest;
use App\Http\Resources\WorkoutLineResource;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\QueryBuilder;

class WorkoutLineController extends Controller
{
    use AuthorizesRequests;

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WorkoutLine::class);

        $lines = QueryBuilder::for(WorkoutLine::class)
            ->allowedFilters(['workout_id'])
            ->whereHas('workout', function ($query): void {
                $query->where('user_id', $this->user()->id);
            })
            ->allowedIncludes(['exercise', 'sets'])
            ->paginate();

        return WorkoutLineResource::collection($lines);
    }

    public function store(WorkoutLineStoreRequest $request): WorkoutLineResource
    {
        /** @var array{workout_id: int, exercise_id: int, notes?: string|null} $validated */
        $validated = $request->validated();

        $workout = Workout::findOrFail($validated['workout_id']);

        $this->authorize('create', [WorkoutLine::class, $workout]);

        $order = $workout->workoutLines()->count();

        /** @var WorkoutLine $workoutLine */
        $workoutLine = $workout->workoutLines()->create(array_merge(
            collect($validated)->except('workout_id')->toArray(),
            ['order' => $order]
        ));

        return new WorkoutLineResource($workoutLine->load(['exercise', 'sets']));
    }

    public function show(WorkoutLine $workoutLine): WorkoutLineResource
    {
        $this->authorize('view', $workoutLine);

        return new WorkoutLineResource($workoutLine->load(['exercise', 'sets']));
    }

    public function update(WorkoutLineUpdateRequest $request, WorkoutLine $workoutLine): WorkoutLineResource
    {
        $this->authorize('update', $workoutLine);

        $workoutLine->update($request->validated());

        return new WorkoutLineResource($workoutLine->load(['exercise', 'sets']));
    }

    public function destroy(WorkoutLine $workoutLine): Response
    {
        $this->authorize('delete', $workoutLine);

        $workoutLine->delete();

        return response()->noContent();
    }
}
