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

/**
 * Controller for managing user achievements.
 *
 * This controller handles the CRUD operations for user achievements,
 * allowing users to view their earned achievements and admins/system
 * to manually award or revoke them. It integrates with Spatie's QueryBuilder
 * for filtering and sorting capabilities.
 */
class UserAchievementController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the user's achievements.
     *
     * Retrieves a paginated list of achievements for the authenticated user.
     * Supports including related achievement details via `include=achievement`.
     * Supports sorting by `achieved_at` and `created_at`.
     *
     * @param  \Illuminate\Http\Request  $request  The incoming HTTP request.
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection The collection of user achievements.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', UserAchievement::class);

        $achievements = QueryBuilder::for(UserAchievement::where('user_id', $this->user()->id))
            ->allowedIncludes(['achievement'])
            ->allowedSorts(['achieved_at', 'created_at'])
            ->paginate();

        return UserAchievementResource::collection($achievements);
    }

    /**
     * Store a newly created user achievement in storage.
     *
     * Manually awards an achievement to the authenticated user.
     * Typically used by admin tools or system processes, but exposed here
     * subject to authorization policies.
     *
     * @param  \App\Http\Requests\Api\StoreUserAchievementRequest  $request  The validated request containing `achievement_id` and optional `achieved_at`.
     * @return \App\Http\Resources\UserAchievementResource The created user achievement resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to create achievements.
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
     * Display the specified user achievement.
     *
     * Retrieves the details of a specific user achievement, including
     * the related achievement definition.
     *
     * @param  \App\Models\UserAchievement  $user_achievement  The user achievement model instance.
     * @return \App\Http\Resources\UserAchievementResource The user achievement resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to view this achievement (e.g., belongs to another user).
     */
    public function show(UserAchievement $user_achievement): UserAchievementResource
    {
        $this->authorize('view', $user_achievement);

        return new UserAchievementResource($user_achievement->load('achievement'));
    }

    /**
     * Update the specified user achievement in storage.
     *
     * Updates the details of an existing user achievement, such as the `achieved_at` date.
     *
     * @param  \App\Http\Requests\Api\UpdateUserAchievementRequest  $request  The validated request containing fields to update.
     * @param  \App\Models\UserAchievement  $user_achievement  The user achievement model instance to update.
     * @return \App\Http\Resources\UserAchievementResource The updated user achievement resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update this achievement.
     */
    public function update(UpdateUserAchievementRequest $request, UserAchievement $user_achievement): UserAchievementResource
    {
        $this->authorize('update', $user_achievement);

        $user_achievement->update($request->validated());

        return new UserAchievementResource($user_achievement->load('achievement'));
    }

    /**
     * Remove the specified user achievement from storage.
     *
     * Permanently deletes (revokes) a user achievement record.
     *
     * @param  \App\Models\UserAchievement  $user_achievement  The user achievement model instance to delete.
     * @return \Illuminate\Http\Response A 204 No Content response.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete this achievement.
     */
    public function destroy(UserAchievement $user_achievement): Response
    {
        $this->authorize('delete', $user_achievement);

        $user_achievement->delete();

        return response()->noContent();
    }
}
