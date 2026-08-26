<?php

declare(strict_types=1);

namespace App\Actions\Habits;

use App\Models\Habit;
use App\Models\User;

final class CreateHabitAction
{
    /**
     * Create a new habit for the user.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data): Habit
    {
        /*
         * `$data` vient d'une requete validee, donc non type. Les tests de
         * verite qui etaient ici acceptaient aussi bien l'absence, la valeur
         * nulle, la chaine vide et le zero — ce qui est le comportement voulu
         * pour un defaut, mais ne se lisait pas. Ce qu'on veut dire : « une
         * couleur, c'est une chaine non vide ; sinon prends celle-ci ».
         */
        if (! is_string($data['color'] ?? null) || $data['color'] === '') {
            $data['color'] = 'bg-palette-ardoise';
        }

        if (! is_string($data['icon'] ?? null) || $data['icon'] === '') {
            $data['icon'] = 'check_circle';
        }

        /** @var Habit */
        return $user->habits()->create($data);
    }
}
