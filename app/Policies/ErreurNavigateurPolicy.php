<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class ErreurNavigateurPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ErreurNavigateur');
    }

    public function view(AuthUser $authUser): bool
    {
        return $authUser->can('View:ErreurNavigateur');
    }

    public function create(): bool
    {
        return false;
    }

    public function update(): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser): bool
    {
        return $authUser->can('Delete:ErreurNavigateur');
    }
}
