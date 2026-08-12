<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class UserPolicy
{
    use HandlesAuthorization;

    /**
     * Whether the authenticated identity is the very user being acted upon.
     *
     * The instanceof check is load-bearing: the back-office authenticates
     * App\Models\Admin on the "admin" guard, a separate table with its own id
     * sequence. Comparing identifiers alone let an admin holding no permission
     * act on the User that happened to share their id.
     */
    private function isSelf(AuthUser $authUser, User $user): bool
    {
        return $authUser instanceof User && $authUser->getKey() === $user->getKey();
    }

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:User');
    }

    public function view(AuthUser $authUser, User $user): bool
    {
        return $this->isSelf($authUser, $user) || $authUser->can('View:User');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:User');
    }

    public function update(AuthUser $authUser, User $user): bool
    {
        return $this->isSelf($authUser, $user) || $authUser->can('Update:User');
    }

    public function delete(AuthUser $authUser, User $user): bool
    {
        return $this->isSelf($authUser, $user) || $authUser->can('Delete:User');
    }

    public function restore(AuthUser $authUser, User $user): bool
    {
        return $this->isSelf($authUser, $user) || $authUser->can('Restore:User');
    }

    public function forceDelete(AuthUser $authUser, User $user): bool
    {
        return $this->isSelf($authUser, $user) || $authUser->can('ForceDelete:User');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:User');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:User');
    }

    public function replicate(AuthUser $authUser, User $user): bool
    {
        return $this->isSelf($authUser, $user) || $authUser->can('Replicate:User');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:User');
    }
}
