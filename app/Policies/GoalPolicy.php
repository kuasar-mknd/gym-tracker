<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Goal;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class GoalPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Goal');
    }

    public function view(AuthUser $authUser, Goal $goal): bool
    {
        return $authUser->can('View:Goal');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Goal');
    }

    public function update(AuthUser $authUser, Goal $goal): bool
    {
        return $authUser->can('Update:Goal');
    }

    public function delete(AuthUser $authUser, Goal $goal): bool
    {
        return $authUser->can('Delete:Goal');
    }

    public function restore(AuthUser $authUser, Goal $goal): bool
    {
        return $authUser->can('Restore:Goal');
    }

    public function forceDelete(AuthUser $authUser, Goal $goal): bool
    {
        return $authUser->can('ForceDelete:Goal');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Goal');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Goal');
    }

    public function replicate(AuthUser $authUser, Goal $goal): bool
    {
        return $authUser->can('Replicate:Goal');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Goal');
    }
}
