<?php

declare(strict_types=1);

namespace App\Actions\Stats;

use App\Models\Exercise;
use App\Models\User;

/**
 * Ce que la vue d'ensemble des statistiques affiche d'emblée : la bibliothèque
 * d'exercices en cache, plus la lecture de la période demandée ; les calculs
 * lourds restent dans les services.
 */
class FetchStatsOverviewAction
{
    /**
     * La liste d'exercices, affichable tout de suite.
     *
     * Une fermeture, qu'Inertia ne résout que si sa prop part dans la réponse :
     * la requête de la prop différée `deferredData`, qui rejoue le contrôleur,
     * relisait sinon la bibliothèque pour la jeter aussitôt (#2148, étape 3a).
     *
     * @param  string  $period  La période demandée, telle qu'elle arrive de la requête (« 30j »).
     * @return array{
     *     exercises: \Closure(): \Illuminate\Database\Eloquent\Collection<int, Exercise>,
     *     selectedPeriod: string
     * }
     */
    public function chiffresImmediats(User $user, string $period): array
    {
        return [
            'exercises' => fn (): \Illuminate\Database\Eloquent\Collection => $this->getFilteredExercises($user->id),
            'selectedPeriod' => $period,
        ];
    }

    /**
     * La période en nombre de jours ; trente pour tout ce qui n'est pas reconnu.
     */
    public function parsePeriod(string $period): int
    {
        return match ($period) {
            '7j' => 7,
            '30j' => 30,
            '90j' => 90,
            '1a' => 365,
            default => 30,
        };
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Exercise>
     */
    private function getFilteredExercises(int $idUtilisateur): \Illuminate\Database\Eloquent\Collection
    {
        return Exercise::enCachePourUtilisateur($idUtilisateur);
    }
}
