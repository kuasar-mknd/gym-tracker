<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\Workout;
use App\Services\Stats\StatsCacheManager;
use Carbon\CarbonInterface;

/**
 * Termine une séance à l'heure du geste, une seule fois (#2049).
 *
 * « Terminer » passe par la file hors ligne : la fin peut arriver des heures
 * après le geste, ou deux fois, quand la réponse de la première s'est perdue.
 * L'heure vient de l'appareil, bornée sur la lecture unique de l'horloge
 * (`Workout::finBornee()`) : jamais après maintenant, jamais avant le début.
 * Une séance déjà close se rend telle quelle, sa première fin gardée : un
 * rejeu ne déplace pas la fin, même avec une autre heure.
 */
final readonly class TerminerLaSeanceAction
{
    public function __construct(private StatsCacheManager $statsCache)
    {
    }

    public function execute(Workout $seance, ?CarbonInterface $finSurLAppareil): Workout
    {
        // Relue : la séance a pu être close depuis sa liaison, par une autre requête.
        $seance->refresh();

        if ($seance->ended_at !== null) {
            return $seance;
        }

        $seance->ended_at = $seance->finBornee($finSurLAppareil, now());
        // Les évènements de la séance oublient la séance en cours du cache, et
        // envoient les travaux des succès et des objectifs.
        $seance->save();

        $this->statsCache->clearWorkoutRelatedStats($seance->user);

        return $seance;
    }
}
