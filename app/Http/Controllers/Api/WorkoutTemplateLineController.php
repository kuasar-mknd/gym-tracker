<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Workouts\CreateWorkoutTemplateLineAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\WorkoutTemplateLineStoreRequest;
use App\Http\Requests\Api\WorkoutTemplateLineUpdateRequest;
use App\Http\Resources\WorkoutTemplateLineResource;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\QueryBuilder;

class WorkoutTemplateLineController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', WorkoutTemplateLine::class);

        $lines = QueryBuilder::for(WorkoutTemplateLine::class)
            ->allowedFilters(['workout_template_id'])
            ->whereHas('workoutTemplate', function ($query) use ($request): void {
                $query->where('user_id', $request->user()?->id);
            })
            ->paginate();

        return WorkoutTemplateLineResource::collection($lines);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(WorkoutTemplateLineStoreRequest $request, CreateWorkoutTemplateLineAction $action): WorkoutTemplateLineResource
    {
        /** @var array{workout_template_id: int, exercise_id: int, order?: int|null} $validated */
        $validated = $request->validated();

        /** @var \App\Models\WorkoutTemplate $workoutTemplate */
        $workoutTemplate = WorkoutTemplate::findOrFail($validated['workout_template_id']);

        $this->authorize('create', [WorkoutTemplateLine::class, $workoutTemplate]);

        $workoutTemplateLine = $action->execute($workoutTemplate, $validated);

        return new WorkoutTemplateLineResource($workoutTemplateLine);
    }

    /**
     * Display the specified resource.
     */
    public function show(WorkoutTemplateLine $workoutTemplateLine): WorkoutTemplateLineResource
    {
        $this->authorize('view', $workoutTemplateLine);

        return new WorkoutTemplateLineResource($workoutTemplateLine);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(WorkoutTemplateLineUpdateRequest $request, WorkoutTemplateLine $workoutTemplateLine): WorkoutTemplateLineResource
    {
        $this->authorize('update', $workoutTemplateLine);

        /** @var array{exercise_id?: int, order?: int|null} $validated */
        $validated = $request->validated();

        $workoutTemplateLine->update($validated);

        return new WorkoutTemplateLineResource($workoutTemplateLine);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(WorkoutTemplateLine $workoutTemplateLine): Response
    {
        $this->authorize('delete', $workoutTemplateLine);

        $workoutTemplateLine->delete();

        return response()->noContent();
    }
}
