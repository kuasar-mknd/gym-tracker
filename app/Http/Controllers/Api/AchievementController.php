<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
     * Display a listing of the resource.
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
            ->allowedFilters(['name', 'category', 'type'])
            ->allowedSorts(['name', 'threshold', 'created_at'])
            ->defaultSort('name')
            ->paginate();

        return AchievementResource::collection($achievements);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(AchievementStoreRequest $request): AchievementResource
    {
        // Authorization handled in Request
        $this->authorize('create', Achievement::class);

        $validated = $request->validated();

        $achievement = Achievement::create($validated);

        return new AchievementResource($achievement);
    }

    /**
     * Display the specified resource.
     */
    public function show(Achievement $achievement): AchievementResource
    {
        $this->authorize('view', $achievement);

        return new AchievementResource($achievement);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(AchievementUpdateRequest $request, Achievement $achievement): AchievementResource
    {
        // Authorization handled in Request
        $this->authorize('update', $achievement);

        $validated = $request->validated();

        $achievement->update($validated);

        return new AchievementResource($achievement);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Achievement $achievement): Response
    {
        $this->authorize('delete', $achievement);

        $achievement->delete();

        return response()->noContent();
    }
}
