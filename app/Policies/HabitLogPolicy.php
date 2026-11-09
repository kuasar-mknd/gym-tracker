<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\User;

final class HabitLogPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * L'appartenance est lue sur le modele plutot que traversee ici : la
     * traversee coutait des requetes que seul le chemin « la ressource existe »
     * payait, ce qui rendait par le chronometre l'existence que #1418 avait
     * retiree du statut et du corps. Voir #1433 et `ResolvesOwnerAtRouteBinding`.
     */
    public function view(User $user, HabitLog $habitLog): bool
    {
        return $habitLog->ownerUserId() === $user->id;
    }

    /**
     * Un journal ne se crée que sur une habitude du compte.
     *
     * L'habitude est exigée (#1513) : sans elle, la politique répondait « oui »,
     * une branche qu'aucun appelant n'atteignait. Le cochage d'une habitude
     * n'interroge pas cette politique, il autorise `update` sur l'habitude
     * (`HabitController::toggle()`).
     */
    public function create(User $user, Habit $habit): bool
    {
        return $user->id === $habit->user_id;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, HabitLog $habitLog): bool
    {
        return $this->view($user, $habitLog);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, HabitLog $habitLog): bool
    {
        return $this->view($user, $habitLog);
    }
}
