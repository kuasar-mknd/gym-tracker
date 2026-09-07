<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class TachePlanifieePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('ViewAny:TachePlanifiee');
    }

    public function view(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('View:TachePlanifiee');
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
