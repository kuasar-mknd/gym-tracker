<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Exercise;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class ExercisePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $utilisateurConnecte): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return true;
        }

        return $utilisateurConnecte->can('ViewAny:Exercise');
    }

    public function view(AuthUser $utilisateurConnecte, Exercise $exercise): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return $exercise->user_id === null || $utilisateurConnecte->id === $exercise->user_id;
        }

        return $utilisateurConnecte->can('View:Exercise');
    }

    public function create(AuthUser $utilisateurConnecte): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return true;
        }

        return $utilisateurConnecte->can('Create:Exercise');
    }

    public function update(AuthUser $utilisateurConnecte, Exercise $exercise): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return $utilisateurConnecte->id === $exercise->user_id;
        }

        return $utilisateurConnecte->can('Update:Exercise');
    }

    public function delete(AuthUser $utilisateurConnecte, Exercise $exercise): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return $utilisateurConnecte->id === $exercise->user_id;
        }

        return $utilisateurConnecte->can('Delete:Exercise');
    }

    public function restore(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Restore:Exercise');
    }

    public function forceDelete(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('ForceDelete:Exercise');
    }

    public function forceDeleteAny(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('ForceDeleteAny:Exercise');
    }

    public function restoreAny(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('RestoreAny:Exercise');
    }

    public function replicate(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Replicate:Exercise');
    }

    public function reorder(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Reorder:Exercise');
    }
}
