<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SupplementLog;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SupplementLogPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        if ($authUser instanceof \App\Models\User) {
            return true;
        }

        return $authUser->can('ViewAny:SupplementLog');
    }

    public function view(AuthUser $authUser, SupplementLog $supplementLog): bool
    {
        if ($authUser instanceof \App\Models\User) {
            return $authUser->id === $supplementLog->user_id;
        }

        return $authUser->can('View:SupplementLog');
    }

    public function create(AuthUser $authUser): bool
    {
        if ($authUser instanceof \App\Models\User) {
            return true;
        }

        return $authUser->can('Create:SupplementLog');
    }

    public function update(AuthUser $authUser, SupplementLog $supplementLog): bool
    {
        if ($authUser instanceof \App\Models\User) {
            return $authUser->id === $supplementLog->user_id;
        }

        return $authUser->can('Update:SupplementLog');
    }

    public function delete(AuthUser $authUser, SupplementLog $supplementLog): bool
    {
        if ($authUser instanceof \App\Models\User) {
            return $authUser->id === $supplementLog->user_id;
        }

        return $authUser->can('Delete:SupplementLog');
    }

    public function restore(AuthUser $authUser, SupplementLog $supplementLog): bool
    {
        return $authUser->can('Restore:SupplementLog');
    }

    public function forceDelete(AuthUser $authUser, SupplementLog $supplementLog): bool
    {
        return $authUser->can('ForceDelete:SupplementLog');
    }
}
