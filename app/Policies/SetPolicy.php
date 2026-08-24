<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Set;
use App\Models\User;
use App\Models\WorkoutLine;

final class SetPolicy
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
     * L'appartenance est lue sur le modele plutot que traversee ici.
     *
     * Deux raisons, et la premiere est de securite. `$set->workoutLine->workout`
     * demandait deux requetes de plus que la liaison du modele, et seulement
     * quand la serie existe : un identifiant inconnu sortait a la premiere. La
     * ressource d'autrui repondait donc mesurablement plus lentement que la
     * ressource absente, ce qui redonne par le chronometre l'existence que
     * #1418 avait retiree du statut et du corps. Voir #1433 et
     * `ResolvesOwnerAtRouteBinding`.
     *
     * La seconde est la robustesse. Le premier maillon peut manquer : la cle
     * etrangere est en `ON DELETE CASCADE`, donc supprimer la ligne emporte la
     * serie, et une requete lente qui a deja resolu son modele lit ensuite une
     * relation qui ne renvoie plus rien. Deferencee sans garde, elle rendait
     * 500. `ownerUserId()` rend `null`, ce qui refuse — et la reponse due est
     * bien 404 : le gardien de `bootstrap/app.php` interroge `view` pour savoir
     * si le refus porte sur une ressource invisible, et une serie sans ligne n'a
     * plus de proprietaire etablissable.
     */
    public function view(User $user, Set $set): bool
    {
        return $set->ownerUserId() === $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, ?WorkoutLine $workoutLine = null): bool
    {
        if ($workoutLine === null) {
            return true;
        }

        return $workoutLine->ownerUserId() === $user->id && $workoutLine->ownerWorkoutIsOngoing();
    }

    /**
     * Determine whether the user can update the model.
     *
     * L'appartenance d'abord, la seance ouverte ensuite : `ownerWorkoutIsOngoing()`
     * lit un `ended_at` nul, ce qu'une chaine rompue rend aussi.
     */
    public function update(User $user, Set $set): bool
    {
        return $this->view($user, $set) && $set->ownerWorkoutIsOngoing();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Set $set): bool
    {
        return $this->view($user, $set) && $set->ownerWorkoutIsOngoing();
    }
}
