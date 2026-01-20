<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Workout;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class WorkoutPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Workout');
    }

    public function view(AuthUser $authUser, Workout $workout): bool
    {
        return $authUser->can('View:Workout');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Workout');
    }

    public function update(AuthUser $authUser, Workout $workout): bool
    {
        return $authUser->can('Update:Workout');
    }

    public function delete(AuthUser $authUser, Workout $workout): bool
    {
        return $authUser->can('Delete:Workout');
    }

    public function restore(AuthUser $authUser, Workout $workout): bool
    {
        return $authUser->can('Restore:Workout');
    }

    public function forceDelete(AuthUser $authUser, Workout $workout): bool
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

    public function replicate(AuthUser $authUser, Workout $workout): bool
    {
        return $authUser->can('Replicate:Workout');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Workout');
    }
}
