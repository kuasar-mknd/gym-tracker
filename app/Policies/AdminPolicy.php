<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Admin;
use Illuminate\Contracts\Auth\Authenticatable;

class AdminPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(Authenticatable $user): bool
    {
        if (! $user instanceof Admin) {
            return false;
        }

        return $user->can('view_any_admin');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(Authenticatable $user, Admin $admin): bool
    {
        if (! $user instanceof Admin) {
            return false;
        }

        return $user->can('view_admin');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(Authenticatable $user): bool
    {
        if (! $user instanceof Admin) {
            return false;
        }

        return $user->can('create_admin');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(Authenticatable $user, Admin $admin): bool
    {
        if (! $user instanceof Admin) {
            return false;
        }

        return $user->can('update_admin');
    }

    /**
     * Determine whether the user can delete the user.
     */
    public function delete(Authenticatable $user, Admin $admin): bool
    {
        if (! $user instanceof Admin) {
            return false;
        }

        return $user->can('delete_admin');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(Authenticatable $user, Admin $admin): bool
    {
        if (! $user instanceof Admin) {
            return false;
        }

        return $user->can('restore_admin');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(Authenticatable $user, Admin $admin): bool
    {
        if (! $user instanceof Admin) {
            return false;
        }

        return $user->can('force_delete_admin');
    }
}
