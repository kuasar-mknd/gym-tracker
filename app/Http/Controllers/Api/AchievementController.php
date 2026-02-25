<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\AchievementResource;
use App\Models\Achievement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Achievements
 */
class AchievementController extends Controller
{
    /**
     * List achievements.
     *
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $achievements = QueryBuilder::for(Achievement::class)
            ->allowedFilters(['name', 'type', 'category'])
            ->allowedSorts(['name', 'created_at', 'threshold'])
            ->defaultSort('name')
            ->paginate($request->integer('per_page', 15));

        return AchievementResource::collection($achievements);
    }

    /**
     * Show achievement.
     *
     * @param Achievement $achievement
     * @return AchievementResource
     */
    public function show(Achievement $achievement): AchievementResource
    {
        return new AchievementResource($achievement);
    }

    /**
     * Create achievement (Admin only - placeholder).
     *
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }

    /**
     * Update achievement (Admin only - placeholder).
     *
     * @return JsonResponse
     */
    public function update(Request $request, Achievement $achievement): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }

    /**
     * Delete achievement (Admin only - placeholder).
     *
     * @return JsonResponse
     */
    public function destroy(Achievement $achievement): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }
}
