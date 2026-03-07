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
use OpenApi\Annotations as OA;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Controller for managing achievements.
 */
class AchievementController extends Controller implements HasMiddleware
{
    use AuthorizesRequests;

    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,App\Models\Achievement', only: ['index']),
            new Middleware('can:view,achievement', only: ['show']),
            new Middleware('can:create,App\Models\Achievement', only: ['store']),
            new Middleware('can:update,achievement', only: ['update']),
            new Middleware('can:delete,achievement', only: ['destroy']),
        ];
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
