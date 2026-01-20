<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateWorkoutTemplateAction;
use App\Actions\UpdateWorkoutTemplateAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\WorkoutTemplateStoreRequest;
use App\Http\Requests\Api\WorkoutTemplateUpdateRequest;
use App\Http\Resources\WorkoutTemplateResource;
use App\Models\WorkoutTemplate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\QueryBuilder;

class WorkoutTemplateController extends Controller
{
    use AuthorizesRequests;

    public function index()
    {
        $this->authorize('viewAny', WorkoutTemplate::class);

        $templates = QueryBuilder::for(WorkoutTemplate::class)
            ->where('user_id', auth()->id())
            ->allowedSorts(['created_at', 'name'])
            ->allowedIncludes(['workoutTemplateLines.exercise', 'workoutTemplateLines.workoutTemplateSets'])
            ->paginate();

        return WorkoutTemplateResource::collection($templates);
    }

    public function store(WorkoutTemplateStoreRequest $request, CreateWorkoutTemplateAction $action)
    {
        $this->authorize('create', WorkoutTemplate::class);

        $template = $action->execute($request->user(), $request->validated());

        return new WorkoutTemplateResource($template->load(['workoutTemplateLines.workoutTemplateSets', 'workoutTemplateLines.exercise']));
    }

    public function show(WorkoutTemplate $workoutTemplate)
    {
        $this->authorize('view', $workoutTemplate);

        return new WorkoutTemplateResource($workoutTemplate->load(['workoutTemplateLines.workoutTemplateSets', 'workoutTemplateLines.exercise']));
    }

    public function update(WorkoutTemplateUpdateRequest $request, WorkoutTemplate $workoutTemplate, UpdateWorkoutTemplateAction $action)
    {
        $this->authorize('update', $workoutTemplate);

        $template = $action->execute($workoutTemplate, $request->validated());

        return new WorkoutTemplateResource($template);
    }

    public function destroy(WorkoutTemplate $workoutTemplate)
    {
        $this->authorize('delete', $workoutTemplate);

        $workoutTemplate->delete();

        return response()->noContent();
    }
}
