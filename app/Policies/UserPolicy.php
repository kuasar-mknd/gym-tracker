<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class UserPolicy
{
    use HandlesAuthorization;

    /**
     * L'identité connectée est-elle l'utilisateur même sur lequel on agit.
     *
     * Le test `instanceof` porte tout le poids : le back-office authentifie
     * App\Models\Admin sur le gardien « admin », une table à part avec sa propre
     * séquence d'identifiants. Comparer les seuls identifiants laissait un
     * administrateur sans aucune permission agir sur le User qui partageait par
     * hasard son id.
     */
    private function estLuiMeme(AuthUser $utilisateurConnecte, User $user): bool
    {
        return $utilisateurConnecte instanceof User && $utilisateurConnecte->getKey() === $user->getKey();
    }

    public function viewAny(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('ViewAny:User');
    }

    public function view(AuthUser $utilisateurConnecte, User $user): bool
    {
        return $this->estLuiMeme($utilisateurConnecte, $user) || $utilisateurConnecte->can('View:User');
    }

    public function create(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Create:User');
    }

    public function update(AuthUser $utilisateurConnecte, User $user): bool
    {
        return $this->estLuiMeme($utilisateurConnecte, $user) || $utilisateurConnecte->can('Update:User');
    }

    public function delete(AuthUser $utilisateurConnecte, User $user): bool
    {
        return $this->estLuiMeme($utilisateurConnecte, $user) || $utilisateurConnecte->can('Delete:User');
    }

    public function restore(AuthUser $utilisateurConnecte, User $user): bool
    {
        return $this->estLuiMeme($utilisateurConnecte, $user) || $utilisateurConnecte->can('Restore:User');
    }

    public function forceDelete(AuthUser $utilisateurConnecte, User $user): bool
    {
        return $this->estLuiMeme($utilisateurConnecte, $user) || $utilisateurConnecte->can('ForceDelete:User');
    }

    public function forceDeleteAny(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('ForceDeleteAny:User');
    }

    public function restoreAny(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('RestoreAny:User');
    }

    public function replicate(AuthUser $utilisateurConnecte, User $user): bool
    {
        return $this->estLuiMeme($utilisateurConnecte, $user) || $utilisateurConnecte->can('Replicate:User');
    }

    public function reorder(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Reorder:User');
    }
}
