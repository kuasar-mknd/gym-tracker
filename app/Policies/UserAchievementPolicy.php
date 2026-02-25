<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\UserAchievement;
use Illuminate\Auth\Access\HandlesAuthorization;

final class UserAchievementPolicy
{
    use HandlesAuthorization;

    public function viewAny(): bool
    {
        return true;
    }

    public function view(User $user, UserAchievement $userAchievement): bool
    {
        return $user->id === $userAchievement->user_id;
    }

    public function create(): bool
    {
        return true;
    }

    public function update(User $user, UserAchievement $userAchievement): bool
    {
        return $user->id === $userAchievement->user_id;
    }

    public function delete(User $user, UserAchievement $userAchievement): bool
    {
        return $user->id === $userAchievement->user_id;
    }
}
