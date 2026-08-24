<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

final class WorkoutLinePolicy
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
     */
    public function view(User $user, WorkoutLine $workoutLine): bool
    {
        return $this->owns($user, $this->workoutOf($workoutLine));
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, ?Workout $workout = null): bool
    {
        if ($workout === null) {
            return true;
        }

        return $this->ownsAndIsOngoing($user, $workout);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, WorkoutLine $workoutLine): bool
    {
        return $this->ownsAndIsOngoing($user, $this->workoutOf($workoutLine));
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WorkoutLine $workoutLine): bool
    {
        return $this->ownsAndIsOngoing($user, $this->workoutOf($workoutLine));
    }

    /**
     * La seance a laquelle la ligne appartient, si elle en a encore une.
     *
     * Le maillon est facultatif par chronologie, non par modelisation :
     * `workout_id` est NOT NULL et la contrainte est en ON DELETE CASCADE, donc
     * la base ne garde jamais d'orpheline durablement. Mais la liaison de
     * modele resout la ligne d'abord et la politique lit la relation ensuite,
     * et une suppression de la seance qui se glisse entre les deux laisse la
     * requete avec une instance dont la relation ne renvoie plus rien.
     *
     * Deferencee sans garde, elle rendait 500. La reponse due est 404 : le
     * gardien de `bootstrap/app.php` (#1418) interroge `view` pour savoir si le
     * refus porte sur une ressource invisible, et une ligne sans seance n'a
     * plus de proprietaire etablissable. Ce qui vaut aussi pour le gardien
     * lui-meme — il appelle `view`, donc garder `update` seule n'aurait
     * deplace la panne que d'un cran, du controleur vers le gestionnaire
     * d'exception.
     */
    private function workoutOf(WorkoutLine $workoutLine): ?Workout // @phpstan-ignore return.unusedType
    {
        return $workoutLine->workout;
    }

    /**
     * La seance existe encore et elle est a cet utilisateur.
     */
    private function owns(User $user, ?Workout $workout): bool
    {
        return $workout instanceof Workout && $user->id === $workout->user_id;
    }

    /**
     * La meme chose, et la seance n'est pas terminee : on n'ecrit pas dans une
     * seance close.
     */
    private function ownsAndIsOngoing(User $user, ?Workout $workout): bool
    {
        return $workout instanceof Workout
            && $user->id === $workout->user_id
            && is_null($workout->ended_at);
    }
}
