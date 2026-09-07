<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Goal;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class GoalPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $utilisateurConnecte): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return true;
        }

        return $utilisateurConnecte->can('ViewAny:Goal');
    }

    public function view(AuthUser $utilisateurConnecte, Goal $goal): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return $utilisateurConnecte->id === $goal->user_id;
        }

        return $utilisateurConnecte->can('View:Goal');
    }

    public function create(AuthUser $utilisateurConnecte): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return true;
        }

        return $utilisateurConnecte->can('Create:Goal');
    }

    public function update(AuthUser $utilisateurConnecte, Goal $goal): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return $utilisateurConnecte->id === $goal->user_id;
        }

        return $utilisateurConnecte->can('Update:Goal');
    }

    public function delete(AuthUser $utilisateurConnecte, Goal $goal): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return $utilisateurConnecte->id === $goal->user_id;
        }

        return $utilisateurConnecte->can('Delete:Goal');
    }

    public function restore(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Restore:Goal');
    }

    public function forceDelete(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('ForceDelete:Goal');
    }

    public function forceDeleteAny(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('ForceDeleteAny:Goal');
    }

    public function restoreAny(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('RestoreAny:Goal');
    }

    public function replicate(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Replicate:Goal');
    }

    public function reorder(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Reorder:Goal');
    }
}
