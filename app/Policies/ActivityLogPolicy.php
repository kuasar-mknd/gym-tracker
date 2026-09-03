<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Lecture seule, et seulement pour un administrateur qui en a la permission
 * Shield : un journal d'audit se consulte, il ne se corrige pas.
 */
final class ActivityLogPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ActivityLog');
    }

    public function view(AuthUser $authUser): bool
    {
        return $authUser->can('View:ActivityLog');
    }

    public function create(): bool
    {
        return false;
    }

    public function update(): bool
    {
        return false;
    }

    public function delete(): bool
    {
        return false;
    }
}
