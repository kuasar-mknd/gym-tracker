<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Supplement;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class SupplementPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $utilisateurConnecte): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return true;
        }

        return $utilisateurConnecte->can('ViewAny:Supplement');
    }

    public function view(AuthUser $utilisateurConnecte, Supplement $supplement): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return $utilisateurConnecte->id === $supplement->user_id;
        }

        return $utilisateurConnecte->can('View:Supplement');
    }

    public function create(AuthUser $utilisateurConnecte): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return true;
        }

        return $utilisateurConnecte->can('Create:Supplement');
    }

    public function update(AuthUser $utilisateurConnecte, Supplement $supplement): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return $utilisateurConnecte->id === $supplement->user_id;
        }

        return $utilisateurConnecte->can('Update:Supplement');
    }

    public function delete(AuthUser $utilisateurConnecte, Supplement $supplement): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return $utilisateurConnecte->id === $supplement->user_id;
        }

        return $utilisateurConnecte->can('Delete:Supplement');
    }

    public function restore(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Restore:Supplement');
    }

    public function forceDelete(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('ForceDelete:Supplement');
    }

    public function forceDeleteAny(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('ForceDeleteAny:Supplement');
    }

    public function restoreAny(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('RestoreAny:Supplement');
    }

    public function replicate(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Replicate:Supplement');
    }

    public function reorder(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Reorder:Supplement');
    }
}
