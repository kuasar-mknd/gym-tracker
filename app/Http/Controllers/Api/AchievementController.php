<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAchievementRequest;
use App\Http\Requests\Api\UpdateAchievementRequest;
use App\Http\Resources\AchievementResource;
use App\Models\Achievement;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\QueryBuilder;

class AchievementController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Achievement::class);

        $achievements = QueryBuilder::for(Achievement::class)
            ->allowedSorts(['name', 'threshold', 'created_at'])
            ->paginate();

        return AchievementResource::collection($achievements);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAchievementRequest $request): AchievementResource
    {
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
    public function update(UpdateAchievementRequest $request, Achievement $achievement): AchievementResource
    {
        $this->authorize('update', $achievement);

        $achievement->update($request->validated());

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
