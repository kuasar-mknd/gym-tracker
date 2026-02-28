<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AchievementStoreRequest;
use App\Http\Requests\Api\AchievementUpdateRequest;
use App\Http\Resources\AchievementResource;
use App\Models\Achievement;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AchievementController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Achievement::class);

        return AchievementResource::collection(Achievement::all());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(AchievementStoreRequest $request): AchievementResource
    {
        /** @var array{slug: string, name: string, description: string, icon: string, type: string, threshold: float|int, category: string} $validated */
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
        /** @var array{slug?: string, name?: string, description?: string, icon?: string, type?: string, threshold?: float|int, category?: string} $validated */
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
