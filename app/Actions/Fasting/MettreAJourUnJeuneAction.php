<?php

declare(strict_types=1);

namespace App\Actions\Fasting;

use App\Models\Fast;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class MettreAJourUnJeuneAction
{
    /**
     * Passer à `active` un jeûne qui ne l'est pas vaut démarrer un jeûne :
     * l'autre jeûne en cours du compte se cherche sous le même verrou que
     * `DemarrerUnJeuneAction`. Toute autre mise à jour s'écrit sans verrou.
     *
     * @param  array<string, mixed>  $donnees
     *
     * @throws ValidationException Si le compte a déjà un autre jeûne en cours.
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException Si le verrou reste pris plus de `DemarrerUnJeuneAction::ATTENTE_DU_VERROU` secondes.
     */
    public function execute(User $user, Fast $fast, array $donnees): Fast
    {
        if (($donnees['status'] ?? null) !== 'active' || $fast->status === 'active') {
            $fast->update($donnees);

            return $fast;
        }

        DemarrerUnJeuneAction::verrouDuCompte($user)
            ->block(DemarrerUnJeuneAction::ATTENTE_DU_VERROU, function () use ($user, $fast, $donnees): void {
                $autreEnCours = $user->fasts()
                    ->where('status', 'active')
                    ->whereKeyNot($fast->getKey())
                    ->exists();

                if ($autreEnCours) {
                    throw ValidationException::withMessages(['base' => DemarrerUnJeuneAction::MESSAGE]);
                }

                $fast->update($donnees);
            });

        return $fast;
    }
}
