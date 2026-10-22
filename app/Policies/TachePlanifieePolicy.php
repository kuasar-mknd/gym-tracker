<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Les tâches planifiées, en lecture seule : le planning se relit du code
 * (`sync-planning`), il ne s'écrit pas ici.
 *
 * Elle répond à chaque méthode que Filament consulte : le panneau est en
 * autorisation stricte, où une méthode absente lève au lieu d'autoriser
 * (`LePanneauAutoriseChaqueActionTest`).
 */
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

    public function deleteAny(): bool
    {
        return false;
    }

    public function forceDelete(): bool
    {
        return false;
    }

    public function forceDeleteAny(): bool
    {
        return false;
    }

    public function restore(): bool
    {
        return false;
    }

    public function restoreAny(): bool
    {
        return false;
    }

    public function replicate(): bool
    {
        return false;
    }

    public function reorder(): bool
    {
        return false;
    }
}
