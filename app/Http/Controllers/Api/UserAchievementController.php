<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreUserAchievementRequest;
use App\Http\Requests\Api\UpdateUserAchievementRequest;
use App\Http\Resources\UserAchievementResource;
use App\Models\UserAchievement;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\QueryBuilder;

class UserAchievementController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', UserAchievement::class);

        $achievements = QueryBuilder::for(UserAchievement::class)
            ->where('user_id', $this->user()->id)
            ->allowedIncludes(['achievement'])
            ->allowedSorts(['achieved_at', 'created_at'])
            ->paginate();

        return UserAchievementResource::collection($achievements);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserAchievementRequest $request): UserAchievementResource
    {
        $this->authorize('create', UserAchievement::class);

        $validated = $request->validated();

        /** @var UserAchievement $userAchievement */
        $userAchievement = UserAchievement::create([
            ...$validated,
            'user_id' => $this->user()->id,
        ]);

        return new UserAchievementResource($userAchievement->load('achievement'));
    }

    /**
     * Display the specified resource.
     */
    public function show(UserAchievement $user_achievement): UserAchievementResource
    {
        $this->authorize('view', $user_achievement);

        return new UserAchievementResource($user_achievement->load('achievement'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserAchievementRequest $request, UserAchievement $user_achievement): UserAchievementResource
    {
        $this->authorize('update', $user_achievement);

        $user_achievement->update($request->validated());

        return new UserAchievementResource($user_achievement->load('achievement'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(UserAchievement $user_achievement): Response
    {
        $this->authorize('delete', $user_achievement);

        $user_achievement->delete();

        return response()->noContent();
    }
}
