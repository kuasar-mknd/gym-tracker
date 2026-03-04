<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreAchievementRequest;
use App\Http\Requests\Api\UpdateAchievementRequest;
use App\Http\Resources\AchievementResource;
use App\Models\Achievement;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use OpenApi\Annotations as OA;

/**
 * Controller for managing achievements.
 */
class AchievementController extends Controller
{
    use AuthorizesRequests;

    public function __construct()
    {
        $this->authorizeResource(Achievement::class, 'achievement');
    }

    /**
     * Display a listing of the resource.
     *
     *
     * @OA\Get(
     *     path="/achievements",
     *     summary="Get list of achievements",
     *     tags={"Achievements"},
     *
     *     @OA\Response(response=200, description="Successful operation"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function index(): AnonymousResourceCollection
    {
        return AchievementResource::collection(Achievement::paginate());
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  StoreAchievementRequest  $request  The request containing achievement data.
     *
     * @OA\Post(
     *     path="/achievements",
     *     summary="Create a new achievement",
     *     tags={"Achievements"},
     *
     *     @OA\Response(response=201, description="Achievement created successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(StoreAchievementRequest $request): AchievementResource
    {
        $achievement = Achievement::create($request->validated());

        return new AchievementResource($achievement);
    }

    /**
     * Display the specified resource.
     *
     * @param  Achievement  $achievement  The achievement to display.
     *
     * @OA\Get(
     *     path="/achievements/{achievement}",
     *     summary="Get a specific achievement",
     *     tags={"Achievements"},
     *
     *     @OA\Response(response=200, description="Successful operation"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Achievement not found")
     * )
     */
    public function show(Achievement $achievement): AchievementResource
    {
        return new AchievementResource($achievement);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  UpdateAchievementRequest  $request  The request containing updated achievement data.
     * @param  Achievement  $achievement  The achievement to update.
     *
     * @OA\Put(
     *     path="/achievements/{achievement}",
     *     summary="Update an existing achievement",
     *     tags={"Achievements"},
     *
     *     @OA\Response(response=200, description="Achievement updated successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Achievement not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(UpdateAchievementRequest $request, Achievement $achievement): AchievementResource
    {
        $achievement->update($request->validated());

        return new AchievementResource($achievement);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  Achievement  $achievement  The achievement to remove.
     *
     * @throws \Exception
     *
     * @OA\Delete(
     *     path="/achievements/{achievement}",
     *     summary="Delete an achievement",
     *     tags={"Achievements"},
     *
     *     @OA\Response(response=204, description="Achievement deleted successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Achievement not found")
     * )
     */
    public function destroy(Achievement $achievement): Response
    {
        $achievement->delete();

        return response()->noContent();
    }
}
