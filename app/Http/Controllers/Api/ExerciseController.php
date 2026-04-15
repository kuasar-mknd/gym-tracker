<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Exercises\FetchExercisesApiAction;
use App\Http\Requests\ExerciseStoreRequest;
use App\Http\Requests\ExerciseUpdateRequest;
use App\Http\Resources\ExerciseResource;
use App\Models\Exercise;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * Controller for managing exercises via API.
 *
 * Provides endpoints for creating, retrieving, updating, and deleting
 * user-specific exercises, as well as accessing global exercises.
 */
class ExerciseController extends Controller
{
    /**
     * Display a listing of the user's exercises.
     *
     * Retrieves a paginated list of exercises belonging to the authenticated user,
     * as well as global exercises (where user_id is null).
     * Supports filtering by name, type, and category, and sorting by name and created_at.
     *
     * @return AnonymousResourceCollection A collection of exercise resources.
     *
     * @throws AuthorizationException If the user is not authorized to view exercises.
     */
    #[OA\Get(
        path: '/exercises',
        summary: 'List user and global exercises',
        tags: ['Exercises']
    )]
    #[OA\Parameter(
        name: 'filter[name]',
        in: 'query',
        required: false,
        description: 'Filter by exercise name',
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Parameter(
        name: 'filter[type]',
        in: 'query',
        required: false,
        description: 'Filter by exercise type',
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Parameter(
        name: 'filter[category]',
        in: 'query',
        required: false,
        description: 'Filter by exercise category',
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Parameter(
        name: 'sort',
        in: 'query',
        required: false,
        description: 'Sort by field (e.g., name, -created_at)',
        schema: new OA\Schema(type: 'string', enum: ['name', '-name', 'created_at', '-created_at'])
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    public function index(FetchExercisesApiAction $action): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Exercise::class);

        $exercises = $action->execute($this->user());

        return ExerciseResource::collection($exercises);
    }

    /**
     * Store a newly created exercise in storage.
     *
     * Validates the request data and creates a new custom exercise for the user.
     *
     * @param  ExerciseStoreRequest  $request  The incoming validated request.
     * @return ExerciseResource The newly created exercise resource.
     *
     * @throws AuthorizationException If the user is not authorized to create an exercise.
     */
    #[OA\Post(
        path: '/exercises',
        summary: 'Create a new exercise',
        tags: ['Exercises']
    )]
    #[OA\Response(response: 201, description: 'Exercise created successfully')]
    #[OA\Response(response: 400, description: 'Bad request')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function store(ExerciseStoreRequest $request): ExerciseResource
    {
        $this->authorize('create', Exercise::class);

        $validated = $request->validated();

        $exercise = new Exercise($validated);
        $exercise->user_id = $this->user()->id;
        $exercise->save();

        return new ExerciseResource($exercise);
    }

    /**
     * Display the specified exercise.
     *
     * Retrieves the details of a specific exercise.
     *
     * @param  Exercise  $exercise  The exercise instance to display.
     * @return ExerciseResource The requested exercise resource.
     *
     * @throws AuthorizationException If the user is not authorized to view the exercise.
     */
    #[OA\Get(
        path: '/exercises/{id}',
        summary: 'Get a specific exercise',
        tags: ['Exercises']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID of the exercise',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Exercise not found')]
    public function show(Exercise $exercise): ExerciseResource
    {
        $this->authorize('view', $exercise);

        return new ExerciseResource($exercise);
    }

    /**
     * Update the specified exercise in storage.
     *
     * Modifies the details of an existing exercise.
     * Only the user who created the exercise can update it.
     *
     * @param  ExerciseUpdateRequest  $request  The incoming validated request.
     * @param  Exercise  $exercise  The exercise instance to update.
     * @return ExerciseResource The updated exercise resource.
     *
     * @throws AuthorizationException If the user is not authorized to update the exercise.
     */
    #[OA\Put(
        path: '/exercises/{id}',
        summary: 'Update an exercise',
        tags: ['Exercises']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID of the exercise',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 200, description: 'Exercise updated successfully')]
    #[OA\Response(response: 400, description: 'Bad request')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Exercise not found')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function update(ExerciseUpdateRequest $request, Exercise $exercise): ExerciseResource
    {
        $this->authorize('update', $exercise);

        $validated = $request->validated();

        $exercise->update($validated);

        return new ExerciseResource($exercise);
    }

    /**
     * Remove the specified exercise from storage.
     *
     * Permanently deletes a custom exercise.
     * Only the user who created the exercise can delete it.
     *
     * @param  Exercise  $exercise  The exercise instance to delete.
     * @return Response An empty HTTP response indicating success.
     *
     * @throws AuthorizationException If the user is not authorized to delete the exercise.
     */
    #[OA\Delete(
        path: '/exercises/{id}',
        summary: 'Delete an exercise',
        tags: ['Exercises']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID of the exercise to delete',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 204, description: 'Exercise deleted successfully')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Exercise not found')]
    public function destroy(Exercise $exercise): Response
    {
        $this->authorize('delete', $exercise);

        $exercise->delete();

        return response()->noContent();
    }
}
