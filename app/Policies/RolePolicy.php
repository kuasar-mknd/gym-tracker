<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class RolePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('ViewAny:Role');
    }

    public function view(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('View:Role');
    }

    public function create(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Create:Role');
    }

    public function update(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Update:Role');
    }

    public function delete(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Delete:Role');
    }

    public function restore(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Restore:Role');
    }

    public function forceDelete(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('ForceDelete:Role');
    }

    public function forceDeleteAny(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('ForceDeleteAny:Role');
    }

    public function restoreAny(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('RestoreAny:Role');
    }

    public function replicate(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Replicate:Role');
    }

    public function reorder(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Reorder:Role');
    }
}
