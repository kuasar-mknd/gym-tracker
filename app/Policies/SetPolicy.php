<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
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
     */
    public function view(User $user, Set $set): bool
    {
        return $this->owns($user, $this->workoutOf($set));
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, ?WorkoutLine $workoutLine = null): bool
    {
        if ($workoutLine === null) {
            return true;
        }

        return $this->ownsAndIsOngoing($user, $workoutLine->workout);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Set $set): bool
    {
        return $this->ownsAndIsOngoing($user, $this->workoutOf($set));
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Set $set): bool
    {
        return $this->ownsAndIsOngoing($user, $this->workoutOf($set));
    }

    /**
     * La seance a laquelle la serie appartient, si elle en a encore une.
     *
     * Le maillon est facultatif par chronologie, non par modelisation :
     * `workout_line_id` est NOT NULL et la contrainte est en ON DELETE CASCADE,
     * donc la base ne garde jamais d'orpheline durablement. Mais la liaison de
     * modele resout la serie d'abord et la politique lit la relation ensuite,
     * et une suppression de la ligne qui se glisse entre les deux laisse la
     * requete avec une instance dont la relation ne renvoie plus rien.
     *
     * Deferencee sans garde, elle rendait 500. La reponse due est 404 : le
     * gardien de `bootstrap/app.php` (#1418) interroge `view` pour savoir si le
     * refus porte sur une ressource invisible, et une serie sans ligne n'a plus
     * de proprietaire etablissable. Ce qui vaut aussi pour le gardien lui-meme —
     * il appelle `view`, donc garder `update` seule n'aurait deplace la panne
     * que d'un cran.
     *
     * Le type nullable est signale inutile par l'analyse : `workout_line_id`
     * etant NOT NULL, elle deduit la relation non nullable. C'est la deduction
     * qui est fausse ici — la colonne dit ce que la base accepte a l'ecriture,
     * pas ce qu'une instance deja resolue lit apres coup. Le modele lui-meme en
     * convient : `Set::recomputeVolume()` deferences la meme relation en `?->`.
     */
    private function workoutOf(Set $set): ?Workout // @phpstan-ignore return.unusedType
    {
        return $set->workoutLine?->workout;
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
