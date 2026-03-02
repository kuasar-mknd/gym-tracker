<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAchievementRequest;
use App\Http\Requests\Api\UpdateAchievementRequest;
use App\Http\Resources\AchievementResource;
use App\Models\Achievement;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AchievementController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Achievement::class, 'achievement');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        return AchievementResource::collection(Achievement::paginate());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAchievementRequest $request): AchievementResource
    {
        $achievement = Achievement::create($request->validated());

        return new AchievementResource($achievement);
    }

    /**
     * Display the specified resource.
     */
    public function show(Achievement $achievement): AchievementResource
    {
        return new AchievementResource($achievement);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAchievementRequest $request, Achievement $achievement): AchievementResource
    {
        $achievement->update($request->validated());

        return new AchievementResource($achievement);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Achievement $achievement): Response
    {
        $achievement->delete();

        return response()->noContent();
    }
}
