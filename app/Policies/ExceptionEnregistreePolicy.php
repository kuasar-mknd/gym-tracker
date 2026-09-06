<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class ExceptionEnregistreePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ExceptionEnregistree');
    }

    public function view(AuthUser $authUser): bool
    {
        return $authUser->can('View:ExceptionEnregistree');
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
        return $authUser->can('Delete:ExceptionEnregistree');
    }
}
