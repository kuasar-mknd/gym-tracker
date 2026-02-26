<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

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
            ->whereHas('workoutTemplate', function ($query): void {
                $query->where('user_id', $this->user()->id);
            })
            ->paginate();

        return WorkoutTemplateLineResource::collection($lines);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(WorkoutTemplateLineStoreRequest $request): WorkoutTemplateLineResource
    {
        $validated = $request->validated();

        /** @var \App\Models\WorkoutTemplate $workoutTemplate */
        $workoutTemplate = WorkoutTemplate::findOrFail($validated['workout_template_id']);

        $this->authorize('create', [WorkoutTemplateLine::class, $workoutTemplate]);

        // @phpstan-ignore-next-line
        $order = $validated['order'] ?? (is_null($workoutTemplate->workoutTemplateLines()->max('order')) ? 0 : $workoutTemplate->workoutTemplateLines()->max('order') + 1);

        /** @var \App\Models\WorkoutTemplateLine $workoutTemplateLine */
        $workoutTemplateLine = $workoutTemplate->workoutTemplateLines()->create(array_merge(
            collect($validated)->except('workout_template_id')->toArray(),
            ['order' => $order]
        ));

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

        $workoutTemplateLine->update($request->validated());

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
