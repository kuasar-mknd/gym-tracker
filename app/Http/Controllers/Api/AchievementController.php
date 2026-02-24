<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\AchievementStoreRequest;
use App\Http\Requests\Api\AchievementUpdateRequest;
use App\Http\Resources\AchievementResource;
use App\Models\Achievement;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;
use Spatie\QueryBuilder\QueryBuilder;

class AchievementController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the achievements.
     */
    #[OA\Get(
        path: '/achievements',
        summary: 'Get list of achievements',
        tags: ['Achievements']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Achievement::class);

        $achievements = QueryBuilder::for(Achievement::class)
            ->allowedFilters(['name', 'category', 'type', 'slug'])
            ->allowedSorts(['name', 'created_at', 'threshold'])
            ->defaultSort('name')
            ->paginate();

        return AchievementResource::collection($achievements);
    }

    /**
     * Store a newly created achievement.
     */
    #[OA\Post(
        path: '/achievements',
        summary: 'Create a new achievement',
        tags: ['Achievements']
    )]
    #[OA\Response(response: 201, description: 'Achievement created successfully')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Unauthorized')]
    public function store(AchievementStoreRequest $request): AchievementResource
    {
        $this->authorize('create', Achievement::class);

        $validated = $request->validated();

        $achievement = Achievement::create($validated);

        return new AchievementResource($achievement);
    }

    /**
     * Display the specified achievement.
     */
    #[OA\Get(
        path: '/achievements/{achievement}',
        summary: 'Get a specific achievement',
        tags: ['Achievements']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 404, description: 'Achievement not found')]
    public function show(Achievement $achievement): AchievementResource
    {
        $this->authorize('view', $achievement);

        return new AchievementResource($achievement);
    }

    /**
     * Update the specified achievement.
     */
    #[OA\Put(
        path: '/achievements/{achievement}',
        summary: 'Update an existing achievement',
        tags: ['Achievements']
    )]
    #[OA\Response(response: 200, description: 'Achievement updated successfully')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Unauthorized')]
    #[OA\Response(response: 404, description: 'Achievement not found')]
    public function update(AchievementUpdateRequest $request, Achievement $achievement): AchievementResource
    {
        $this->authorize('update', $achievement);

        $validated = $request->validated();

        $achievement->update($validated);

        return new AchievementResource($achievement);
    }

    /**
     * Remove the specified achievement.
     */
    #[OA\Delete(
        path: '/achievements/{achievement}',
        summary: 'Delete an achievement',
        tags: ['Achievements']
    )]
    #[OA\Response(response: 204, description: 'Achievement deleted successfully')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 403, description: 'Unauthorized')]
    #[OA\Response(response: 404, description: 'Achievement not found')]
    public function destroy(Achievement $achievement): Response
    {
        $this->authorize('delete', $achievement);

        $achievement->delete();

        return response()->noContent();
    }
}
