<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AchievementStoreRequest;
use App\Http\Requests\Api\AchievementUpdateRequest;
use App\Http\Resources\AchievementResource;
use App\Models\Achievement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;

class AchievementController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    #[OA\Get(
        path: '/api/v1/achievements',
        operationId: 'getAchievements',
        summary: 'Get list of achievements',
        tags: ['Achievements'],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Achievement::class);

        $achievements = Achievement::query()
            ->latest()
            ->paginate();

        return AchievementResource::collection($achievements);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \App\Http\Requests\Api\AchievementStoreRequest $request
     * @return \App\Http\Resources\AchievementResource
     */
    #[OA\Post(
        path: '/api/v1/achievements',
        operationId: 'storeAchievement',
        summary: 'Create a new achievement',
        tags: ['Achievements'],
        responses: [
            new OA\Response(response: 201, description: 'Successful operation'),
            new OA\Response(response: 400, description: 'Bad Request'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(AchievementStoreRequest $request): AchievementResource
    {
        Gate::authorize('create', Achievement::class);

        $achievement = Achievement::create($request->validated());

        return new AchievementResource($achievement);
    }

    /**
     * Display the specified resource.
     *
     * @param \App\Models\Achievement $achievement
     * @return \App\Http\Resources\AchievementResource
     */
    #[OA\Get(
        path: '/api/v1/achievements/{id}',
        operationId: 'getAchievement',
        summary: 'Get an achievement',
        tags: ['Achievements'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Achievement id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not Found'),
        ]
    )]
    public function show(Achievement $achievement): AchievementResource
    {
        Gate::authorize('view', $achievement);

        return new AchievementResource($achievement);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \App\Http\Requests\Api\AchievementUpdateRequest $request
     * @param \App\Models\Achievement $achievement
     * @return \App\Http\Resources\AchievementResource
     */
    #[OA\Put(
        path: '/api/v1/achievements/{id}',
        operationId: 'updateAchievement',
        summary: 'Update an achievement',
        tags: ['Achievements'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Achievement id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 400, description: 'Bad Request'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not Found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(AchievementUpdateRequest $request, Achievement $achievement): AchievementResource
    {
        Gate::authorize('update', $achievement);

        $achievement->update($request->validated());

        return new AchievementResource($achievement);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Models\Achievement $achievement
     * @return \Illuminate\Http\JsonResponse
     */
    #[OA\Delete(
        path: '/api/v1/achievements/{id}',
        operationId: 'deleteAchievement',
        summary: 'Delete an achievement',
        tags: ['Achievements'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Achievement id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(response: 204, description: 'Successful operation'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not Found'),
        ]
    )]
    public function destroy(Achievement $achievement): JsonResponse
    {
        Gate::authorize('delete', $achievement);

        $achievement->delete();

        return response()->json(null, 204);
    }
}
