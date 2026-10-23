<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Admin;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;
use Spatie\Permission\Models\Role;

/**
 * Les rôles de Shield. Le rôle du super administrateur ouvre, par son nom,
 * les capacités de `AppServiceProvider::ouvrirLesOutilsAuSuperAdministrateur()` :
 * il ne se modifie que par qui le porte, et ne se supprime pas, ni ligne à
 * ligne, ni en groupe (`authorizeIndividualRecords()` repasse par `delete()`).
 * Son nom ne change pas (`AppServiceProvider::garderLeNomDuRoleDuSuperAdministrateur()`).
 */
final class RolePolicy
{
    use HandlesAuthorization;

    private function estLeRoleDuSuperAdministrateur(Role $role): bool
    {
        return $role->getAttribute('name') === Admin::roleDuSuperAdministrateur();
    }

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

    public function update(AuthUser $utilisateurConnecte, Role $role): bool
    {
        if ($this->estLeRoleDuSuperAdministrateur($role)
            && ! ($utilisateurConnecte instanceof Admin && $utilisateurConnecte->estSuperAdministrateur())) {
            return false;
        }

        return $utilisateurConnecte->can('Update:Role');
    }

    public function delete(AuthUser $utilisateurConnecte, Role $role): bool
    {
        return ! $this->estLeRoleDuSuperAdministrateur($role) && $utilisateurConnecte->can('Delete:Role');
    }

    /**
     * La suppression groupée du panneau : `DeleteAny:Role`, puis
     * `delete()` pour chaque ligne choisie (`authorizeIndividualRecords()`).
     */
    public function deleteAny(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('DeleteAny:Role');
    }

    public function restore(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Restore:Role');
    }

    public function forceDelete(AuthUser $utilisateurConnecte, Role $role): bool
    {
        return ! $this->estLeRoleDuSuperAdministrateur($role) && $utilisateurConnecte->can('ForceDelete:Role');
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
