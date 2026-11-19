<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Exceptions\UneSeanceEstDejaEnCours;
use App\Models\User;
use App\Models\Workout;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;

/**
 * Rouvre une séance que le compte vient de terminer (#2434) : « Reprendre » du
 * bilan de fin de séance.
 *
 * Le droit est celui de `WorkoutPolicy::reopen()` : la séance du compte, close
 * depuis peu (`Workout::DELAI_DE_REOUVERTURE_MINUTES`). Une séance d'historique
 * ne se rouvre pas, et `update()` n'est pas affaibli : il exige toujours une
 * séance ouverte.
 *
 * Comme le démarrage et la fin, la relecture et l'écriture se font sous le
 * verrou des séances du compte (`DemarrerUneSeanceAction::sousLeVerrouDuCompte()`).
 * Si une autre séance du compte s'est ouverte depuis la fin, la réouverture
 * est refusée (`UneSeanceEstDejaEnCours`) : le compte n'a jamais deux séances
 * ouvertes. Une séance déjà ouverte se rend telle quelle (un rejeu).
 * L'enregistrement passe par `save()`, dont les évènements oublient la séance
 * en cours du cache.
 */
final readonly class ReouvrirUneSeanceAction
{
    /**
     * @param  CarbonInterface|null  $maintenant  l'instant lu en tête par l'appelant (`.ai/rules/horloge.md`)
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si la séance n'existe pas.
     * @throws \Illuminate\Auth\Access\AuthorizationException Si la séance n'est pas rouvrable par ce compte.
     * @throws UneSeanceEstDejaEnCours Si une autre séance du compte est en cours.
     * @throws \App\Exceptions\LeVerrouDesSeancesEstPris Si le verrou du compte reste pris trop longtemps.
     */
    public function execute(User $compte, int $seanceId, ?CarbonInterface $maintenant = null): Workout
    {
        $maintenant ??= now();

        /** @var Workout $rouverte */
        $rouverte = DemarrerUneSeanceAction::sousLeVerrouDuCompte($compte->id, function () use ($compte, $seanceId, $maintenant): Workout {
            $seance = Workout::query()->findOrFail($seanceId);

            if ($seance->ended_at === null) {
                Gate::forUser($compte)->authorize('view', $seance);

                return $seance;
            }

            Gate::forUser($compte)->authorize('reopen', [$seance, $maintenant]);

            $enCours = Workout::query()
                ->where('user_id', $compte->id)
                ->whereNull('ended_at')
                ->first();

            if ($enCours !== null) {
                throw new UneSeanceEstDejaEnCours($enCours);
            }

            $seance->ended_at = null;
            $seance->save();

            return $seance;
        });

        return $rouverte;
    }
}
