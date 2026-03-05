<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkoutTemplateSetRequest;
use App\Http\Requests\UpdateWorkoutTemplateSetRequest;
use App\Http\Resources\WorkoutTemplateSetResource;
use App\Models\WorkoutTemplateSet;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\QueryBuilder;

class WorkoutTemplateSetController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(WorkoutTemplateSet::class, 'workout_template_set');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        /** @var \Spatie\QueryBuilder\QueryBuilder<\App\Models\WorkoutTemplateSet> $builder */
        $builder = QueryBuilder::for(WorkoutTemplateSet::class);

        $userId = $this->user()->getAuthIdentifier();

        $workoutTemplateSets = $builder
            ->allowedFilters(['workout_template_line_id'])
            ->allowedSorts(['order', 'created_at', 'updated_at'])
            ->whereHas('workoutTemplateLine', function ($query) use ($userId): void {
                $query->whereHas('workoutTemplate', function ($q) use ($userId): void {
                    $q->where('user_id', $userId);
                });
            })
            ->get();

        return WorkoutTemplateSetResource::collection($workoutTemplateSets);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreWorkoutTemplateSetRequest $request): WorkoutTemplateSetResource
    {
        $workoutTemplateSet = WorkoutTemplateSet::create($request->validated());

        return new WorkoutTemplateSetResource($workoutTemplateSet);
    }

    /**
     * Display the specified resource.
     */
    public function show(WorkoutTemplateSet $workoutTemplateSet): WorkoutTemplateSetResource
    {
        return new WorkoutTemplateSetResource($workoutTemplateSet);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateWorkoutTemplateSetRequest $request, WorkoutTemplateSet $workoutTemplateSet): WorkoutTemplateSetResource
    {
        $workoutTemplateSet->update($request->validated());

        return new WorkoutTemplateSetResource($workoutTemplateSet);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(WorkoutTemplateSet $workoutTemplateSet): Response
    {
        $workoutTemplateSet->delete();

        return response()->noContent();
    }
}
