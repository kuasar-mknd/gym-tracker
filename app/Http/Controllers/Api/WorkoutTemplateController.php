<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateWorkoutTemplateAction;
use App\Actions\UpdateWorkoutTemplateAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkoutTemplateResource;
use App\Models\WorkoutTemplate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\QueryBuilder;

class WorkoutTemplateController extends Controller
{
    use AuthorizesRequests;

    public function index(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', WorkoutTemplate::class);

        // @phpstan-ignore-next-line
        $templates = QueryBuilder::for(WorkoutTemplate::class)
            ->where('user_id', $this->user()->id)
            ->allowedSorts(['created_at', 'name'])
            ->allowedIncludes(['workoutTemplateLines.exercise', 'workoutTemplateLines.workoutTemplateSets']);

        $templates = $templates->paginate();

        return WorkoutTemplateResource::collection($templates);
    }

    public function store(Request $request, CreateWorkoutTemplateAction $action): WorkoutTemplateResource
    {
        $this->authorize('create', WorkoutTemplate::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'exercises' => 'nullable|array',
            'exercises.*.id' => 'required|exists:exercises,id',
            'exercises.*.sets' => 'nullable|array',
            'exercises.*.sets.*.reps' => 'nullable|integer',
            'exercises.*.sets.*.weight' => 'nullable|numeric',
            'exercises.*.sets.*.is_warmup' => 'boolean',
        ]);

        $template = $action->execute($this->user(), $validated);

        return new WorkoutTemplateResource($template->load(['workoutTemplateLines.workoutTemplateSets', 'workoutTemplateLines.exercise']));
    }

    public function show(WorkoutTemplate $workoutTemplate): WorkoutTemplateResource
    {
        $this->authorize('view', $workoutTemplate);

        return new WorkoutTemplateResource($workoutTemplate->load(['workoutTemplateLines.workoutTemplateSets', 'workoutTemplateLines.exercise']));
    }

    public function update(Request $request, WorkoutTemplate $workoutTemplate, UpdateWorkoutTemplateAction $action): WorkoutTemplateResource
    {
        $this->authorize('update', $workoutTemplate);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'exercises' => 'nullable|array',
            'exercises.*.id' => 'required|exists:exercises,id',
            'exercises.*.sets' => 'nullable|array',
            'exercises.*.sets.*.reps' => 'nullable|integer',
            'exercises.*.sets.*.weight' => 'nullable|numeric',
            'exercises.*.sets.*.is_warmup' => 'boolean',
        ]);

        $template = $action->execute($workoutTemplate, $validated);

        return new WorkoutTemplateResource($template);
    }

    public function destroy(WorkoutTemplate $workoutTemplate): \Illuminate\Http\Response
    {
        $this->authorize('delete', $workoutTemplate);

        $workoutTemplate->delete();

        return response()->noContent();
    }
}
