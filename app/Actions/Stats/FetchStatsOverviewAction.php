<?php

declare(strict_types=1);

namespace App\Actions\Stats;

use App\Models\Exercise;
use App\Models\User;
use App\Services\Stats\BodyStatsService;

/**
 * Ce que la vue d'ensemble des statistiques affiche d'emblée.
 *
 * Les mesures corporelles et la liste d'exercices en cache, plus la lecture de
 * la période demandée ; les calculs lourds restent dans les services.
 */
class FetchStatsOverviewAction
{
    public function __construct(protected BodyStatsService $bodyStats)
    {
    }

    /**
     * Les chiffres légers, affichables tout de suite : dernières mesures
     * corporelles et liste d'exercices en cache.
     *
     * @param  string  $period  La période demandée, telle qu'elle arrive de la requête (« 30j »).
     * @return array<string, mixed>
     */
    public function getImmediateStats(User $user, string $period): array
    {
        $bodyMetrics = $this->bodyStats->getLatestBodyMetrics($user);

        return [
            'latestWeight' => $bodyMetrics->latest_weight,
            'weightChange' => $bodyMetrics->weight_change,
            'bodyFat' => $bodyMetrics->latest_body_fat,
            'exercises' => $this->getFilteredExercises($user->id),
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
    private function getFilteredExercises(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return Exercise::getCachedForUser($userId);
    }
}
