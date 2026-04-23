<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\CreateWorkoutTemplateAction;
use App\Actions\UpdateWorkoutTemplateAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\WorkoutTemplateStoreRequest;
use App\Http\Requests\Api\WorkoutTemplateUpdateRequest;
use App\Http\Resources\WorkoutTemplateResource;
use App\Models\WorkoutTemplate;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Controller for managing user workout templates via API.
 *
 * Provides endpoints to list, create, retrieve, update, and delete
 * workout templates for the authenticated user.
 */
class WorkoutTemplateController extends Controller
{
    /**
     * Display a listing of the user's workout templates.
     *
     * Retrieves a paginated list of workout templates belonging to the authenticated user.
     * Supports sorting and eager loading of relationships.
     *
     * @return AnonymousResourceCollection A collection of workout template resources.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    #[OA\Get(
        path: '/workout-templates',
        summary: 'Get list of workout templates',
        tags: ['Workout Templates']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WorkoutTemplate::class);

        /** @var QueryBuilder<WorkoutTemplate> $templates */
        $templates = clone QueryBuilder::for(WorkoutTemplate::class)->where('user_id', $this->user()->id);

        $templates->allowedSorts(['created_at', 'name'])
            ->allowedIncludes(['workoutTemplateLines.exercise', 'workoutTemplateLines.workoutTemplateSets']);

        $templates = $templates->paginate();

        return WorkoutTemplateResource::collection($templates);
    }

    /**
     * Store a newly created workout template in storage.
     *
     * Validates the request data and creates a new workout template for the user.
     *
     * @param  WorkoutTemplateStoreRequest  $request  The incoming validated request.
     * @param  CreateWorkoutTemplateAction  $action   Action class to handle creation.
     * @return WorkoutTemplateResource The newly created workout template resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    #[OA\Post(
        path: '/workout-templates',
        summary: 'Create a new workout template',
        tags: ['Workout Templates']
    )]
    #[OA\Response(response: 201, description: 'Created successfully')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function store(WorkoutTemplateStoreRequest $request, CreateWorkoutTemplateAction $action): WorkoutTemplateResource
    {
        $this->authorize('create', WorkoutTemplate::class);

        /** @var array{name: string, description?: string|null, exercises?: array<int, array{id: int, sets?: array<int, array{reps?: int|null, weight?: float|null, is_warmup?: bool}>}>} $validated */
        $validated = $request->validated();

        $template = $action->execute($this->user(), $validated);

        return new WorkoutTemplateResource($template->load(['workoutTemplateLines.workoutTemplateSets', 'workoutTemplateLines.exercise']));
    }

    /**
     * Display the specified workout template.
     *
     * Retrieves the details of a specific workout template, including its lines, sets, and exercises.
     *
     * @param  WorkoutTemplate  $workoutTemplate  The workout template instance to display.
     * @return WorkoutTemplateResource The requested workout template resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    #[OA\Get(
        path: '/workout-templates/{id}',
        summary: 'Get a specific workout template',
        tags: ['Workout Templates']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID of the workout template',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Not found')]
    public function show(WorkoutTemplate $workoutTemplate): WorkoutTemplateResource
    {
        $this->authorize('view', $workoutTemplate);

        return new WorkoutTemplateResource($workoutTemplate->load(['workoutTemplateLines.workoutTemplateSets', 'workoutTemplateLines.exercise']));
    }

    /**
     * Update the specified workout template in storage.
     *
     * Modifies the details of an existing workout template.
     *
     * @param  WorkoutTemplateUpdateRequest  $request          The incoming validated request.
     * @param  WorkoutTemplate               $workoutTemplate  The workout template instance to update.
     * @param  UpdateWorkoutTemplateAction   $action           Action class to handle update.
     * @return WorkoutTemplateResource The updated workout template resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    #[OA\Put(
        path: '/workout-templates/{id}',
        summary: 'Update a workout template',
        tags: ['Workout Templates']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID of the workout template',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 200, description: 'Updated successfully')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Not found')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function update(WorkoutTemplateUpdateRequest $request, WorkoutTemplate $workoutTemplate, UpdateWorkoutTemplateAction $action): WorkoutTemplateResource
    {
        $this->authorize('update', $workoutTemplate);

        /** @var array{name: string, description?: string|null, exercises?: array<int, array{id: int, sets?: array<int, array{reps?: int|null, weight?: float|null, is_warmup?: bool}>}>} $validated */
        $validated = $request->validated();

        $template = $action->execute($workoutTemplate, $validated);

        return new WorkoutTemplateResource($template);
    }

    /**
     * Remove the specified workout template from storage.
     *
     * Permanently deletes a workout template.
     *
     * @param  WorkoutTemplate  $workoutTemplate  The workout template instance to delete.
     * @return Response An empty HTTP response indicating success.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    #[OA\Delete(
        path: '/workout-templates/{id}',
        summary: 'Delete a workout template',
        tags: ['Workout Templates']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID of the workout template to delete',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 204, description: 'Deleted successfully')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Not found')]
    public function destroy(WorkoutTemplate $workoutTemplate): Response
    {
        $this->authorize('delete', $workoutTemplate);

        $workoutTemplate->delete();

        return response()->noContent();
    }
}
