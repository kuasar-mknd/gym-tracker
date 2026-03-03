<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\WorkoutTemplateSetStoreRequest;
use App\Http\Requests\Api\WorkoutTemplateSetUpdateRequest;
use App\Http\Resources\WorkoutTemplateSetResource;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\QueryBuilder;

class WorkoutTemplateSetController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', WorkoutTemplateSet::class);

        $sets = QueryBuilder::for(WorkoutTemplateSet::class)
            ->allowedFilters(['workout_template_line_id'])
            ->whereHas('workoutTemplateLine.workoutTemplate', function ($query) use ($request): void {
                $query->where('user_id', $request->user()?->id);
            })
            ->paginate();

        return WorkoutTemplateSetResource::collection($sets);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(WorkoutTemplateSetStoreRequest $request): WorkoutTemplateSetResource
    {
        /** @var array{workout_template_line_id: int, reps?: int|null, weight?: float|null, is_warmup?: bool, order?: int|null} $validated */
        $validated = $request->validated();

        /** @var \App\Models\WorkoutTemplateLine $workoutTemplateLine */
        $workoutTemplateLine = WorkoutTemplateLine::findOrFail($validated['workout_template_line_id']);

        $this->authorize('create', [WorkoutTemplateSet::class, $workoutTemplateLine]);

        /** @var int|null $maxOrder */
        $maxOrder = $workoutTemplateLine->workoutTemplateSets()->max('order');
        $order = $validated['order'] ?? ($maxOrder === null ? 0 : $maxOrder + 1);

        /** @var \App\Models\WorkoutTemplateSet $workoutTemplateSet */
        $workoutTemplateSet = $workoutTemplateLine->workoutTemplateSets()->create(array_merge(
            collect($validated)->except('workout_template_line_id')->toArray(),
            ['order' => $order]
        ));

        return new WorkoutTemplateSetResource($workoutTemplateSet);
    }

    /**
     * Display the specified resource.
     */
    public function show(WorkoutTemplateSet $workoutTemplateSet): WorkoutTemplateSetResource
    {
        $this->authorize('view', $workoutTemplateSet);

        return new WorkoutTemplateSetResource($workoutTemplateSet);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(WorkoutTemplateSetUpdateRequest $request, WorkoutTemplateSet $workoutTemplateSet): WorkoutTemplateSetResource
    {
        $this->authorize('update', $workoutTemplateSet);

        /** @var array{reps?: int|null, weight?: float|null, is_warmup?: bool, order?: int|null} $validated */
        $validated = $request->validated();

        $workoutTemplateSet->update($validated);

        return new WorkoutTemplateSetResource($workoutTemplateSet);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(WorkoutTemplateSet $workoutTemplateSet): Response
    {
        $this->authorize('delete', $workoutTemplateSet);

        $workoutTemplateSet->delete();

        return response()->noContent();
    }
}
