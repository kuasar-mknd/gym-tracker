<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Les exceptions enregistrées, que le panneau lit et supprime ; rien ne les
 * crée ni ne les modifie à la main.
 *
 * Elle répond à chaque méthode que Filament consulte : le panneau est en
 * autorisation stricte, où une méthode absente lève au lieu d'autoriser
 * (`LePanneauAutoriseChaqueActionTest`).
 */
final class ExceptionEnregistreePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('ViewAny:ExceptionEnregistree');
    }

    public function view(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('View:ExceptionEnregistree');
    }

    public function create(): bool
    {
        return false;
    }

    public function update(): bool
    {
        return false;
    }

    public function delete(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('Delete:ExceptionEnregistree');
    }

    /**
     * La suppression groupée du panneau : `DeleteAny:ExceptionEnregistree`, puis
     * `delete()` pour chaque ligne choisie (`authorizeIndividualRecords()`).
     */
    public function deleteAny(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('DeleteAny:ExceptionEnregistree');
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
