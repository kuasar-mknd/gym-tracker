<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreAchievementRequest;
use App\Http\Requests\Api\UpdateAchievementRequest;
use App\Http\Resources\AchievementResource;
use App\Models\Achievement;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Spatie\QueryBuilder\QueryBuilder;

class AchievementController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/achievements',
        summary: 'Get list of achievements',
        tags: ['Achievements']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function index(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', Achievement::class);

        $achievements = QueryBuilder::for(Achievement::class)
            ->allowedSorts(['name', 'created_at', 'category', 'type'])
            ->allowedFilters(['category', 'type'])
            ->defaultSort('name')
            ->paginate();

        return AchievementResource::collection($achievements);
    }

    #[OA\Post(
        path: '/achievements',
        summary: 'Create a new achievement',
        tags: ['Achievements']
    )]
    #[OA\Response(response: 201, description: 'Created successfully')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function store(StoreAchievementRequest $request): AchievementResource
    {
        $this->authorize('create', Achievement::class);

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $achievement = Achievement::create($validated);

        return new AchievementResource($achievement);
    }

    #[OA\Get(
        path: '/achievements/{achievement}',
        summary: 'Get a specific achievement',
        tags: ['Achievements']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 404, description: 'Not found')]
    public function show(Achievement $achievement): AchievementResource
    {
        $this->authorize('view', $achievement);

        return new AchievementResource($achievement);
    }

    #[OA\Put(
        path: '/achievements/{achievement}',
        summary: 'Update an achievement',
        tags: ['Achievements']
    )]
    #[OA\Response(response: 200, description: 'Updated successfully')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function update(UpdateAchievementRequest $request, Achievement $achievement): AchievementResource
    {
        $this->authorize('update', $achievement);

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $achievement->update($validated);

        return new AchievementResource($achievement);
    }

    #[OA\Delete(
        path: '/achievements/{achievement}',
        summary: 'Delete an achievement',
        tags: ['Achievements']
    )]
    #[OA\Response(response: 204, description: 'Deleted successfully')]
    public function destroy(Achievement $achievement): \Illuminate\Http\Response
    {
        $this->authorize('delete', $achievement);

        $achievement->delete();

        return response()->noContent();
    }
}
