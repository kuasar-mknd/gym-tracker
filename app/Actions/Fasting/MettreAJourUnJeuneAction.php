<?php

declare(strict_types=1);

namespace App\Actions\Fasting;

use App\Models\Fast;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class MettreAJourUnJeuneAction
{
    public const string MESSAGE_REPRISE_AVEC_FIN = 'Reprendre un jeûne ne se fait pas avec une fin.';

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
        $reprend = ($donnees['status'] ?? null) === 'active' && $fast->status !== 'active';
        $pose = isset($donnees['end_time']);

        throw_if($reprend && $pose, ValidationException::withMessages(['end_time' => self::MESSAGE_REPRISE_AVEC_FIN]));

        if (! $reprend && ! $pose) {
            $fast->update($donnees);

            return $fast;
        }

        DemarrerUnJeuneAction::sousLeVerrou($user, fn () => $pose
            ? FinDUnJeune::poser($fast, $donnees)
            : $this->reprendre($user, $fast, $donnees));

        return $fast;
    }

    /**
     * @param  array<string, mixed>  $donnees
     *
     * @throws ValidationException
     */
    private function reprendre(User $user, Fast $fast, array $donnees): void
    {
        $autreEnCours = $user->fasts()
            ->where('status', 'active')
            ->whereKeyNot($fast->getKey())
            ->exists();

        if ($autreEnCours) {
            throw ValidationException::withMessages(['base' => DemarrerUnJeuneAction::MESSAGE]);
        }

        $fast->update($donnees);
    }
}
