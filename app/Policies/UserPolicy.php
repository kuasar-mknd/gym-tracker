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
    private function isSelf(AuthUser $authUser, User $user): bool
    {
        return $authUser instanceof User && $authUser->getKey() === $user->getKey();
    }

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:User');
    }

    public function view(AuthUser $authUser, User $user): bool
    {
        return $this->isSelf($authUser, $user) || $authUser->can('View:User');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:User');
    }

    public function update(AuthUser $authUser, User $user): bool
    {
        return $this->isSelf($authUser, $user) || $authUser->can('Update:User');
    }

    public function delete(AuthUser $authUser, User $user): bool
    {
        return $this->isSelf($authUser, $user) || $authUser->can('Delete:User');
    }

    public function restore(AuthUser $authUser, User $user): bool
    {
        return $this->isSelf($authUser, $user) || $authUser->can('Restore:User');
    }

    public function forceDelete(AuthUser $authUser, User $user): bool
    {
        return $this->isSelf($authUser, $user) || $authUser->can('ForceDelete:User');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:User');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:User');
    }

    public function replicate(AuthUser $authUser, User $user): bool
    {
        return $this->isSelf($authUser, $user) || $authUser->can('Replicate:User');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:User');
    }
}
