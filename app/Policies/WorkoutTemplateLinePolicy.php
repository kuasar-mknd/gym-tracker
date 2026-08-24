<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkoutTemplate;
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
     */
    public function view(User $user, WorkoutTemplateLine $workoutTemplateLine): bool
    {
        return $this->owns($user, $this->parentDe($workoutTemplateLine));
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, ?WorkoutTemplate $workoutTemplate = null): bool
    {
        if ($workoutTemplate === null) {
            return true;
        }

        return $this->owns($user, $workoutTemplate);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, WorkoutTemplateLine $workoutTemplateLine): bool
    {
        return $this->owns($user, $this->parentDe($workoutTemplateLine));
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WorkoutTemplateLine $workoutTemplateLine): bool
    {
        return $this->owns($user, $this->parentDe($workoutTemplateLine));
    }

    /**
     * Le modele auquel l'enregistrement appartient, s'il en a encore un.
     *
     * Le maillon est facultatif par chronologie, non par modelisation :
     * `workout_template_id` est NOT NULL et la contrainte est en ON DELETE CASCADE, donc la
     * base ne garde jamais d'orphelin durablement. Mais la liaison de modele
     * resout l'enfant d'abord et la politique lit la relation ensuite, et une
     * suppression du parent qui se glisse entre les deux laisse la requete avec
     * une instance dont la relation ne renvoie plus rien.
     *
     * Deferencee sans garde, elle rendait 500. La reponse due est 404 : le
     * gardien de `bootstrap/app.php` (#1418) interroge `view` pour savoir si le
     * refus porte sur une ressource invisible, et un enfant sans parent n'a
     * plus de proprietaire etablissable. Ce qui vaut aussi pour le gardien
     * lui-meme — il appelle `view`, donc garder `update` seule n'aurait
     * deplace la panne que d'un cran.
     */
    private function parentDe(WorkoutTemplateLine $workoutTemplateLine): ?WorkoutTemplate // @phpstan-ignore return.unusedType
    {
        return $workoutTemplateLine->workoutTemplate;
    }

    /**
     * Le parent existe encore et il est a cet utilisateur.
     */
    private function owns(User $user, ?WorkoutTemplate $workoutTemplate): bool
    {
        return $workoutTemplate instanceof WorkoutTemplate && $user->id === $workoutTemplate->user_id;
    }
}
