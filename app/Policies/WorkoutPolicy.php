<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Workout;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class WorkoutPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $utilisateurConnecte): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return true;
        }

        return $utilisateurConnecte->can('ViewAny:Workout');
    }

    public function view(AuthUser $utilisateurConnecte, Workout $workout): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return $utilisateurConnecte->id === $workout->user_id;
        }

        return $utilisateurConnecte->can('View:Workout');
    }

    public function create(AuthUser $utilisateurConnecte): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return true;
        }

        return $utilisateurConnecte->can('Create:Workout');
    }

    public function update(AuthUser $utilisateurConnecte, Workout $workout): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return $utilisateurConnecte->id === $workout->user_id && is_null($workout->ended_at);
        }

        return $utilisateurConnecte->can('Update:Workout');
    }

    public function delete(AuthUser $utilisateurConnecte, Workout $workout): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return $utilisateurConnecte->id === $workout->user_id;
        }

        return $utilisateurConnecte->can('Delete:Workout');
    }

    public function restore(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Restore:Workout');
    }

    public function forceDelete(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('ForceDelete:Workout');
    }

    public function forceDeleteAny(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('ForceDeleteAny:Workout');
    }

    public function restoreAny(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('RestoreAny:Workout');
    }

    public function replicate(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Replicate:Workout');
    }

    public function reorder(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Reorder:Workout');
    }
}
