<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\User;
use App\Models\Workout;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;

/**
 * Termine une séance à l'heure du geste, une seule fois (#2049).
 *
 * « Terminer » passe par la file hors ligne : la fin peut arriver des heures
 * après le geste, ou deux fois, quand la réponse de la première s'est perdue.
 * L'heure vient de l'appareil, par l'âge du geste lu sur sa seule horloge
 * (`App\Support\HeureDeLAppareil`), placé sur la lecture unique de
 * l'horloge du serveur et borné (`Workout::finBornee()`) : jamais après
 * maintenant, jamais avant le début.
 * Une séance déjà close se rend telle quelle, sa première fin gardée : un
 * rejeu ne déplace pas la fin, même avec une autre heure.
 *
 * La relecture et l'écriture se font sous le verrou des séances du compte
 * (`DemarrerUneSeanceAction::verrouDuCompte()`) : deux fins qui se croisent
 * (deux appareils dont la file se vide au même retour du réseau, une fin
 * directe et son rejeu, la fin d'une page d'avant #2049) passent l'une après
 * l'autre, et la seconde trouve la séance close. Sans lui, la seconde passait
 * entre la relecture et l'écriture de la première, qui l'écrasait ensuite :
 * l'heure gardée n'était plus celle de la première fin arrivée, et chaque
 * appareil montrait la sienne. Pas d'écriture conditionnelle à la place
 * (`whereNull('ended_at')->update()`) : elle sauterait les évènements de la
 * séance, qui oublient la séance en cours du cache et envoient les travaux
 * des succès et des objectifs ; ni de transaction, que ces travaux
 * devanceraient. Une écriture qui ne passe pas par les actions (le panneau)
 * n'est pas sérialisée.
 */
final readonly class TerminerLaSeanceAction
{
    /**
     * Termine la séance de ce compte que nomme son identifiant, lue une seule
     * fois, sous le verrou : l'appelant n'a pas à la charger avant. L'horloge
     * se lit une fois, en tête, avant l'attente du verrou (`.ai/rules/horloge.md`).
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si la séance n'existe pas.
     * @throws \Illuminate\Auth\Access\AuthorizationException Si le compte n'a pas le droit de la voir.
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException Si le verrou du compte reste pris trop longtemps.
     */
    public function terminerCelleDuCompte(User $compte, int $seanceId, ?int $ageDuGeste): Workout
    {
        $maintenant = now();

        /** @var Workout $close */
        $close = DemarrerUneSeanceAction::verrouDuCompte($compte->id)
            ->block(DemarrerUneSeanceAction::ATTENTE_DU_VERROU, function () use ($compte, $seanceId, $ageDuGeste, $maintenant): Workout {
                $seance = Workout::query()->findOrFail($seanceId);
                Gate::forUser($compte)->authorize('view', $seance);

                return $this->ecrireLaFin($seance, $ageDuGeste, $maintenant);
            });

        return $close;
    }

    private function ecrireLaFin(Workout $seance, ?int $ageDuGeste, CarbonInterface $maintenant): Workout
    {
        if ($seance->ended_at !== null) {
            return $seance;
        }

        $seance->ended_at = $seance->finBornee($ageDuGeste, $maintenant);
        // Les évènements de la séance oublient la séance en cours du cache, et
        // envoient les travaux des succès et des objectifs.
        $seance->save();

        return $seance;
    }
}
