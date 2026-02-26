<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\AchievementResource;
use App\Models\Achievement;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\QueryBuilder;

class AchievementController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Achievement::class);

        $achievements = QueryBuilder::for(Achievement::class)
            ->allowedFilters(['name', 'category', 'type'])
            ->allowedSorts(['name', 'created_at'])
            ->paginate();

        return AchievementResource::collection($achievements);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(): void
    {
        $this->authorize('create', Achievement::class);
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
    public function update(Achievement $achievement): void
    {
        $this->authorize('update', $achievement);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Achievement $achievement): void
    {
        $this->authorize('delete', $achievement);
    }
}
