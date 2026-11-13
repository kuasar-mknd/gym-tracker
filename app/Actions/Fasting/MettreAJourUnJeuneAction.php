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
     * `DemarrerUnJeuneAction`. Poser une fin s'écrit sous ce verrou aussi : le
     * jeûne est relu, et la fin d'un jeûne qui n'est plus `active` ne bouge
     * pas : une fin rejouée par un second appareil ne la déplace pas. Un jeûne
     * repris puis terminé de nouveau prend sa nouvelle fin. Toute autre mise à
     * jour s'écrit sans verrou.
     *
     * @param  array<string, mixed>  $donnees
     *
     * @throws ValidationException Si le compte a déjà un autre jeûne en cours, ou si le verrou reste pris plus de `DemarrerUnJeuneAction::ATTENTE_DU_VERROU` secondes.
     */
    public function execute(User $user, Fast $fast, array $donnees): Fast
    {
        $reprend = ($donnees['status'] ?? null) === 'active' && $fast->status !== 'active';
        $pose = ($donnees['end_time'] ?? null) !== null;

        if (! $reprend && ! $pose) {
            $fast->update($donnees);

            return $fast;
        }

        DemarrerUnJeuneAction::sousLeVerrou($user, function () use ($user, $fast, $donnees, $reprend, $pose): void {
            if ($pose) {
                $fast->refresh();

                if ($fast->status !== 'active' && $fast->end_time !== null) {
                    unset($donnees['end_time']);
                }
            }

            if ($reprend) {
                $autreEnCours = $user->fasts()
                    ->where('status', 'active')
                    ->whereKeyNot($fast->getKey())
                    ->exists();

                if ($autreEnCours) {
                    throw ValidationException::withMessages(['base' => DemarrerUnJeuneAction::MESSAGE]);
                }
            }

            $fast->update($donnees);
        });

        return $fast;
    }
}
