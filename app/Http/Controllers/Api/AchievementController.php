<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AchievementStoreRequest;
use App\Http\Requests\Api\AchievementUpdateRequest;
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
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection<int, \App\Http\Resources\AchievementResource>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Achievement::class);

        /** @var \Spatie\QueryBuilder\QueryBuilder<\App\Models\Achievement> $query */
        $query = QueryBuilder::for(Achievement::class);

        $achievements = $query->allowedSorts(['name', 'threshold', 'created_at'])
            ->allowedFilters(['category', 'type'])
            ->paginate();

        return AchievementResource::collection($achievements);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(AchievementStoreRequest $request): AchievementResource
    {
        $this->authorize('create', Achievement::class);

        /** @var \App\Models\Achievement $achievement */
        $achievement = Achievement::create($request->validated());

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
