<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Achievement;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AchievementPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        if ($authUser instanceof \App\Models\User) {
            return true;
        }

        return $authUser->can('ViewAny:Achievement');
    }

    public function view(AuthUser $authUser, Achievement $achievement): bool
    {
        if ($authUser instanceof \App\Models\User) {
            return true;
        }

        return $authUser->can('View:Achievement');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Achievement');
    }

    public function update(AuthUser $authUser, Achievement $achievement): bool
    {
        if ($authUser instanceof \App\Models\User) {
            return false;
        }

        return $authUser->can('Update:Achievement');
    }

    public function delete(AuthUser $authUser, Achievement $achievement): bool
    {
        if ($authUser instanceof \App\Models\User) {
            return false;
        }

        return $authUser->can('Delete:Achievement');
    }

    public function restore(AuthUser $authUser, Achievement $achievement): bool
    {
        return $authUser->can('Restore:Achievement');
    }

    public function forceDelete(AuthUser $authUser, Achievement $achievement): bool
    {
        return $authUser->can('ForceDelete:Achievement');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Achievement');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Achievement');
    }

    public function replicate(AuthUser $authUser, Achievement $achievement): bool
    {
        return $authUser->can('Replicate:Achievement');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Achievement');
    }
}
