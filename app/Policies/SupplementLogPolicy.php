<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SupplementLog;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SupplementLogPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $utilisateurConnecte): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return true;
        }

        return $utilisateurConnecte->can('ViewAny:SupplementLog');
    }

    public function view(AuthUser $utilisateurConnecte, SupplementLog $supplementLog): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return $utilisateurConnecte->id === $supplementLog->user_id;
        }

        return $utilisateurConnecte->can('View:SupplementLog');
    }

    public function create(AuthUser $utilisateurConnecte): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return true;
        }

        return $utilisateurConnecte->can('Create:SupplementLog');
    }

    public function update(AuthUser $utilisateurConnecte, SupplementLog $supplementLog): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return $utilisateurConnecte->id === $supplementLog->user_id;
        }

        return $utilisateurConnecte->can('Update:SupplementLog');
    }

    public function delete(AuthUser $utilisateurConnecte, SupplementLog $supplementLog): bool
    {
        if ($utilisateurConnecte instanceof \App\Models\User) {
            return $utilisateurConnecte->id === $supplementLog->user_id;
        }

        return $utilisateurConnecte->can('Delete:SupplementLog');
    }

    public function restore(AuthUser $utilisateurConnecte, SupplementLog $supplementLog): bool
    {
        return $utilisateurConnecte->can('Restore:SupplementLog');
    }

    public function forceDelete(AuthUser $utilisateurConnecte, SupplementLog $supplementLog): bool
    {
        return $utilisateurConnecte->can('ForceDelete:SupplementLog');
    }
}
