<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\GoalStoreRequest;
use App\Http\Requests\GoalUpdateRequest;
use App\Http\Resources\GoalResource;
use App\Models\Goal;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Controller for managing goals via API.
 */
class GoalController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the user's goals.
     *
     * Retrieves a paginated list of goals belonging to the authenticated user.
     * Supports sorting and including the related exercise.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    #[OA\Get(
        path: '/goals',
        summary: 'Get list of goals',
        tags: ['Goals']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    public function index(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', Goal::class);

        $goals = QueryBuilder::for(Goal::class)
            ->allowedIncludes(['exercise'])
            ->allowedSorts(['deadline', 'progress', 'created_at'])
            ->defaultSort('-created_at')
            ->where('user_id', Auth::id())
            ->paginate();

        return GoalResource::collection($goals);
    }

    /**
     * Store a newly created goal in storage.
     *
     * @param  GoalStoreRequest  $request  The request containing goal data.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    #[OA\Post(
        path: '/goals',
        summary: 'Create a new goal',
        tags: ['Goals']
    )]
    #[OA\Response(response: 201, description: 'Goal created successfully')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function store(GoalStoreRequest $request): GoalResource
    {
        // Authorization handled in GoalStoreRequest::authorize() (returns true)
        // Ideally should be checked here or policy.
        // If GoalStoreRequest returns true, then we should check 'create' ability.
        $this->authorize('create', Goal::class);

        $validated = $request->validated();

        $goal = new Goal($validated);
        $goal->user_id = $this->user()->id;
        $goal->save();

        return new GoalResource($goal);
    }

    /**
     * Display the specified goal.
     *
     * @param  Goal  $goal  The goal to display.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    #[OA\Get(
        path: '/goals/{goal}',
        summary: 'Get a specific goal',
        tags: ['Goals']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Goal not found')]
    public function show(Goal $goal): GoalResource
    {
        $this->authorize('view', $goal);

        $goal->load(['exercise']);

        return new GoalResource($goal);
    }

    /**
     * Update the specified goal in storage.
     *
     * @param  GoalUpdateRequest  $request  The request containing updated goal data.
     * @param  Goal  $goal  The goal to update.
     */
    #[OA\Put(
        path: '/goals/{goal}',
        summary: 'Update an existing goal',
        tags: ['Goals']
    )]
    #[OA\Response(response: 200, description: 'Goal updated successfully')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Goal not found')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function update(GoalUpdateRequest $request, Goal $goal): GoalResource
    {
        // Authorization handled in GoalUpdateRequest::authorize()

        $validated = $request->validated();

        $goal->update($validated);

        return new GoalResource($goal);
    }

    /**
     * Remove the specified goal from storage.
     *
     * @param  Goal  $goal  The goal to remove.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    #[OA\Delete(
        path: '/goals/{goal}',
        summary: 'Delete a goal',
        tags: ['Goals']
    )]
    #[OA\Response(response: 204, description: 'Goal deleted successfully')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Goal not found')]
    public function destroy(Goal $goal): \Illuminate\Http\Response
    {
        $this->authorize('delete', $goal);

        $goal->delete();

        return response()->noContent();
    }
}
