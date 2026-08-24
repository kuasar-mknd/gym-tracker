<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkoutTemplateSet;

final class WorkoutTemplateSetPolicy
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
    public function view(User $user, WorkoutTemplateSet $workoutTemplateSet): bool
    {
        return $workoutTemplateSet->ownerUserId() === $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, ?\App\Models\WorkoutTemplateLine $workoutTemplateLine = null): bool
    {
        if ($workoutTemplateLine === null) {
            return true;
        }

        return $workoutTemplateLine->ownerUserId() === $user->id;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, WorkoutTemplateSet $workoutTemplateSet): bool
    {
        return $this->view($user, $workoutTemplateSet);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WorkoutTemplateSet $workoutTemplateSet): bool
    {
        return $this->view($user, $workoutTemplateSet);
    }
}
