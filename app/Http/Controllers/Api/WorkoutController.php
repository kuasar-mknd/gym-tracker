<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\WorkoutStoreRequest;
use App\Http\Requests\WorkoutUpdateRequest;
use App\Http\Resources\WorkoutResource;
use App\Models\Workout;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use OpenApi\Attributes as OA;

class WorkoutController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the resource.
     */
    #[OA\Get(
        path: '/workouts',
        summary: 'Get list of workouts',
        tags: ['Workouts']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function index(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', Workout::class);

        $workouts = \Spatie\QueryBuilder\QueryBuilder::for(Workout::class)
            ->allowedIncludes(['workoutLines', 'workoutLines.exercise', 'workoutLines.sets'])
            ->allowedSorts(['started_at', 'ended_at', 'created_at'])
            ->defaultSort('-started_at')
            ->where('user_id', $this->user()->id)
            ->paginate();

        return WorkoutResource::collection($workouts);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(WorkoutStoreRequest $request): WorkoutResource
    {
        $validated = $request->validated();

        $workout = new Workout($validated);
        $workout->user_id = $this->user()->id;
        $workout->save();

        \App\Jobs\RecalculateUserStats::dispatch($this->user());

        return new WorkoutResource($workout);
    }

    /**
     * Display the specified resource.
     */
    public function show(Workout $workout): WorkoutResource
    {
        $this->authorize('view', $workout);

        $workout->load(['workoutLines.exercise', 'workoutLines.sets']);

        return new WorkoutResource($workout);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(WorkoutUpdateRequest $request, Workout $workout): WorkoutResource
    {
        $validated = $request->validated();

        $workout->update($validated);

        \App\Jobs\RecalculateUserStats::dispatch($workout->user);

        return new WorkoutResource($workout);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Workout $workout): \Illuminate\Http\Response
    {
        $this->authorize('delete', $workout);

        $user = $workout->user;
        $workout->delete();

        \App\Jobs\RecalculateUserStats::dispatch($user);

        return response()->noContent();
    }
}
