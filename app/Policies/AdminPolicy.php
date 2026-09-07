<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Admin;
use Illuminate\Contracts\Auth\Authenticatable;

class AdminPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        if (! $user instanceof Admin) {
            return false;
        }

        return $user->can('view_any_admin');
    }

    public function view(Authenticatable $user, Admin $admin): bool
    {
        if (! $user instanceof Admin) {
            return false;
        }

        return $user->can('view_admin');
    }

    public function create(Authenticatable $user): bool
    {
        if (! $user instanceof Admin) {
            return false;
        }

        return $user->can('create_admin');
    }

    public function update(Authenticatable $user, Admin $admin): bool
    {
        if (! $user instanceof Admin) {
            return false;
        }

        return $user->can('update_admin');
    }

    public function delete(Authenticatable $user, Admin $admin): bool
    {
        if (! $user instanceof Admin) {
            return false;
        }

        return $user->can('delete_admin');
    }

    public function restore(Authenticatable $user, Admin $admin): bool
    {
        if (! $user instanceof Admin) {
            return false;
        }

        return $user->can('restore_admin');
    }

    public function forceDelete(Authenticatable $user, Admin $admin): bool
    {
        if (! $user instanceof Admin) {
            return false;
        }

        return $user->can('force_delete_admin');
    }
}
