<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\GoalStoreRequest;
use App\Http\Requests\GoalUpdateRequest;
use App\Http\Resources\GoalResource;
use App\Models\Goal;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;
use Spatie\QueryBuilder\QueryBuilder;

class GoalController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/goals',
        summary: 'Get list of goals',
        tags: ['Goals']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function index()
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

    public function store(GoalStoreRequest $request)
    {
        // Authorization handled in GoalStoreRequest::authorize() (returns true)
        // Ideally should be checked here or policy.
        // If GoalStoreRequest returns true, then we should check 'create' ability.
        $this->authorize('create', Goal::class);

        $validated = $request->validated();

        $goal = new Goal($validated);
        $goal->user_id = Auth::id();
        $goal->save();

        return new GoalResource($goal);
    }

    public function show(Goal $goal)
    {
        $this->authorize('view', $goal);

        $goal->load(['exercise']);

        return new GoalResource($goal);
    }

    public function update(GoalUpdateRequest $request, Goal $goal)
    {
        // Authorization handled in GoalUpdateRequest::authorize()

        $validated = $request->validated();

        $goal->update($validated);

        return new GoalResource($goal);
    }

    public function destroy(Goal $goal)
    {
        $this->authorize('delete', $goal);

        $goal->delete();

        return response()->noContent();
    }
}
