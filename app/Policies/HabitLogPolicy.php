<?php

declare(strict_types=1);

namespace App\Policies;

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
     * Determine whether the user can create models.
     */
    public function create(User $user, ?\App\Models\Habit $habit = null): bool
    {
        if ($habit === null) {
            return true;
        }

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
