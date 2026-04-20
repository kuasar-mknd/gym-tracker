<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Workouts\StoreSetAction;
use App\Http\Requests\Api\SetStoreRequest;
use App\Http\Requests\Api\SetUpdateRequest;
use App\Http\Resources\SetResource;
use App\Models\Set;
use App\Services\StatsService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Controller for managing workout sets via API.
 *
 * Handles CRUD operations for the Set model, ensuring users can only manage their own sets.
 * Also handles clearing related volume stats upon modifications.
 */
class SetController extends Controller
{
    /**
     * Initializes the controller with necessary services.
     *
     * @param  StatsService  $statsService  The service used to handle user statistics.
     */
    public function __construct(
        protected StatsService $statsService
    ) {
    }

    /**
     * Display a listing of the sets for the authenticated user.
     *
     * Retrieves a paginated list of sets belonging to the user's workouts.
     * Supports filtering by workout_line_id.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection A collection of set resources.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized.
     */
    #[OA\Get(
        path: '/sets',
        summary: 'Get list of workout sets',
        tags: ['Sets']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    public function index(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', Set::class);

        $sets = QueryBuilder::for(Set::class)
            ->allowedFilters(['workout_line_id'])
            // Bolt: Optimize belongsTo filtering with INNER JOIN
            ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
            ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
            ->where('workouts.user_id', $this->user()->id)
            ->select('sets.*')
            ->paginate();

        return SetResource::collection($sets);
    }

    /**
     * Store a newly created set in storage.
     *
     * Validates the request, ensures the user owns the associated workout line,
     * and saves the new set.
     *
     * @param  SetStoreRequest  $request  The validated incoming HTTP request.
     * @param  StoreSetAction  $action  The action to execute the creation logic.
     * @return SetResource The created set resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the workout line is not found.
     */
    #[OA\Post(
        path: '/sets',
        summary: 'Create a new set',
        tags: ['Sets']
    )]
    #[OA\Response(response: 201, description: 'Created successfully')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function store(SetStoreRequest $request, StoreSetAction $action): SetResource
    {
        /** @var array{workout_line_id: int} $validated */
        $validated = $request->validated();
        $workoutLine = \App\Models\WorkoutLine::findOrFail($validated['workout_line_id']);

        $this->authorize('create', [\App\Models\Set::class, $workoutLine]);

        $set = $action->execute($this->user(), $validated);

        return new SetResource($set->loadMissing('personalRecord'));
    }

    /**
     * Display the specified set.
     *
     * Retrieves the details of a specific set if the authenticated user owns it.
     *
     * @param  Set  $set  The set model instance.
     * @return SetResource The set resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to view the set.
     */
    #[OA\Get(
        path: '/sets/{set}',
        summary: 'Get a specific set',
        tags: ['Sets']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Not found')]
    public function show(Set $set): SetResource
    {
        $this->authorize('view', $set);

        return new SetResource($set);
    }

    /**
     * Update the specified set in storage.
     *
     * Validates the request, applies updates to the set, and clears volume-related user statistics.
     *
     * @param  SetUpdateRequest  $request  The validated incoming HTTP request.
     * @param  Set  $set  The set model instance to update.
     * @return SetResource The updated set resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update the set.
     */
    #[OA\Put(
        path: '/sets/{set}',
        summary: 'Update a set',
        tags: ['Sets']
    )]
    #[OA\Response(response: 200, description: 'Updated successfully')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Not found')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function update(SetUpdateRequest $request, Set $set): SetResource
    {
        $this->authorize('update', $set);

        $set->update($request->validated());

        // Bolt: Only clear volume-related stats for set updates
        $this->statsService->clearVolumeStats($this->user());

        return new SetResource($set->loadMissing('personalRecord'));
    }

    /**
     * Remove the specified set from storage.
     *
     * Deletes the set from the database and clears volume-related user statistics.
     *
     * @param  Set  $set  The set model instance to delete.
     * @return \Illuminate\Http\Response An empty HTTP response.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete the set.
     */
    #[OA\Delete(
        path: '/sets/{set}',
        summary: 'Delete a set',
        tags: ['Sets']
    )]
    #[OA\Response(response: 204, description: 'Deleted successfully')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Not found')]
    public function destroy(Set $set): \Illuminate\Http\Response
    {
        $this->authorize('delete', $set);

        $user = $this->user();
        $set->delete();

        // Bolt: Only clear volume-related stats for set deletions
        $this->statsService->clearVolumeStats($user);

        return response()->noContent();
    }
}
