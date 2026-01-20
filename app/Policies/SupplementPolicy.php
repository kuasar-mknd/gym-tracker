<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Supplement;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SupplementPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        if ($authUser instanceof \App\Models\User) {
            return true;
        }

        return $authUser->can('ViewAny:Supplement');
    }

    public function view(AuthUser $authUser, Supplement $supplement): bool
    {
        if ($authUser instanceof \App\Models\User) {
            return $authUser->id === $supplement->user_id;
        }

        return $authUser->can('View:Supplement');
    }

    public function create(AuthUser $authUser): bool
    {
        if ($authUser instanceof \App\Models\User) {
            return true;
        }

        return $authUser->can('Create:Supplement');
    }

    public function update(AuthUser $authUser, Supplement $supplement): bool
    {
        if ($authUser instanceof \App\Models\User) {
            return $authUser->id === $supplement->user_id;
        }

        return $authUser->can('Update:Supplement');
    }

    public function delete(AuthUser $authUser, Supplement $supplement): bool
    {
        if ($authUser instanceof \App\Models\User) {
            return $authUser->id === $supplement->user_id;
        }

        return $authUser->can('Delete:Supplement');
    }

    public function restore(AuthUser $authUser, Supplement $supplement): bool
    {
        return $authUser->can('Restore:Supplement');
    }

    public function forceDelete(AuthUser $authUser, Supplement $supplement): bool
    {
        return $authUser->can('ForceDelete:Supplement');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Supplement');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Supplement');
    }

    public function replicate(AuthUser $authUser, Supplement $supplement): bool
    {
        return $authUser->can('Replicate:Supplement');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Supplement');
    }
}
