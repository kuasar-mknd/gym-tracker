<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Exercise;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ExercisePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Exercise');
    }

    public function view(AuthUser $authUser, Exercise $exercise): bool
    {
        return $authUser->can('View:Exercise');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Exercise');
    }

    public function update(AuthUser $authUser, Exercise $exercise): bool
    {
        return $authUser->can('Update:Exercise');
    }

    public function delete(AuthUser $authUser, Exercise $exercise): bool
    {
        return $authUser->can('Delete:Exercise');
    }

    public function restore(AuthUser $authUser, Exercise $exercise): bool
    {
        return $authUser->can('Restore:Exercise');
    }

    public function forceDelete(AuthUser $authUser, Exercise $exercise): bool
    {
        return $authUser->can('ForceDelete:Exercise');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Exercise');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Exercise');
    }

    public function replicate(AuthUser $authUser, Exercise $exercise): bool
    {
        return $authUser->can('Replicate:Exercise');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Exercise');
    }
}
