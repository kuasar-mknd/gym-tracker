<?php

declare(strict_types=1);

namespace App\Actions\Fasting;

use App\Models\Fast;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class MettreAJourUnJeuneAction
{
    /**
     * Passer à `active` un jeûne qui ne l'est pas vaut démarrer un jeûne :
     * l'autre jeûne en cours du compte se cherche sous le même verrou que
     * `DemarrerUnJeuneAction`. Poser une fin s'écrit sous ce verrou aussi
     * (`FinDUnJeune`). Toute autre mise à jour s'écrit sans verrou.
     *
     * @param  array<string, mixed>  $donnees
     *
     * @throws ValidationException Si le compte a déjà un autre jeûne en cours, si la reprise arrive avec une fin, ou si le verrou reste pris plus de `DemarrerUnJeuneAction::ATTENTE_DU_VERROU` secondes.
     */
    public function execute(User $user, Fast $fast, array $donnees): Fast
    {
        $reprend = Arr::get($donnees, 'status') === 'active' && $fast->status !== 'active';

        if (! $reprend && ! isset($donnees['end_time'])) {
            $fast->update($donnees);

            return $fast;
        }

        DemarrerUnJeuneAction::sousLeVerrou($user, fn () => $reprend
            ? ReprendreUnJeune::reprendre($user, $fast, $donnees)
            : FinDUnJeune::poser($fast, $donnees));

        return $fast;
    }
}
