<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Workout;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class WorkoutPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        if ($authUser instanceof \App\Models\User) {
            return true;
        }

        return $authUser->can('ViewAny:Workout');
    }

    public function view(AuthUser $authUser, Workout $workout): bool
    {
        if ($authUser instanceof \App\Models\User) {
            return $authUser->id === $workout->user_id;
        }

        return $authUser->can('View:Workout');
    }

    public function create(AuthUser $authUser): bool
    {
        if ($authUser instanceof \App\Models\User) {
            return true;
        }

        return $authUser->can('Create:Workout');
    }

    public function update(AuthUser $authUser, Workout $workout): bool
    {
        if ($authUser instanceof \App\Models\User) {
            return $authUser->id === $workout->user_id && is_null($workout->ended_at);
        }

        return $authUser->can('Update:Workout');
    }

    public function delete(AuthUser $authUser, Workout $workout): bool
    {
        if ($authUser instanceof \App\Models\User) {
            return $authUser->id === $workout->user_id;
        }

        return $authUser->can('Delete:Workout');
    }

    public function restore(AuthUser $authUser): bool
    {
        return $authUser->can('Restore:Workout');
    }

    public function forceDelete(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDelete:Workout');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Workout');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Workout');
    }

    public function replicate(AuthUser $authUser): bool
    {
        return $authUser->can('Replicate:Workout');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Workout');
    }
}
