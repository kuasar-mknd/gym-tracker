<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAchievementRequest;
use App\Http\Requests\Api\UpdateAchievementRequest;
use App\Http\Resources\AchievementResource;
use App\Models\Achievement;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\QueryBuilder;

class AchievementController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', Achievement::class);

        $achievements = QueryBuilder::for(Achievement::class)
            ->allowedFilters(['name', 'type', 'category'])
            ->allowedSorts(['name', 'threshold', 'created_at'])
            ->defaultSort('name')
            ->paginate();

        // Load unlock status for the current user
        $achievements->getCollection()->load([
            'users' => function ($query) {
                $query->where('user_id', $this->user()->id);
            },
        ]);

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

        $achievement->load([
            'users' => function ($query) {
                $query->where('user_id', $this->user()->id);
            },
        ]);

        return new AchievementResource($achievement);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAchievementRequest $request, Achievement $achievement): AchievementResource
    {
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
