<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkoutTemplateLine;

final class WorkoutTemplateLinePolicy
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
    public function view(User $user, WorkoutTemplateLine $workoutTemplateLine): bool
    {
        return $workoutTemplateLine->ownerUserId() === $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, ?\App\Models\WorkoutTemplate $workoutTemplate = null): bool
    {
        if ($workoutTemplate === null) {
            return true;
        }

        return $user->id === $workoutTemplate->user_id;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, WorkoutTemplateLine $workoutTemplateLine): bool
    {
        return $this->view($user, $workoutTemplateLine);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WorkoutTemplateLine $workoutTemplateLine): bool
    {
        return $this->view($user, $workoutTemplateLine);
    }
}
