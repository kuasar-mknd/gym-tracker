<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Les erreurs du navigateur, que le panneau lit et supprime ; seule la page
 * qui les rapporte les crée.
 *
 * Elle répond à chaque méthode que Filament consulte : le panneau est en
 * autorisation stricte, où une méthode absente lève au lieu d'autoriser
 * (`LePanneauAutoriseChaqueActionTest`).
 */
final class ErreurNavigateurPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('ViewAny:ErreurNavigateur');
    }

    public function view(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('View:ErreurNavigateur');
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
        return $utilisateurConnecte->can('Delete:ErreurNavigateur');
    }

    /**
     * La suppression groupée du panneau : `DeleteAny:ErreurNavigateur`, puis
     * `delete()` pour chaque ligne choisie (`authorizeIndividualRecords()`).
     */
    public function deleteAny(AuthUser $utilisateurConnecte): bool
    {
        return $utilisateurConnecte->can('DeleteAny:ErreurNavigateur');
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
