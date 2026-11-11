<?php

declare(strict_types=1);

namespace App\Actions\Stats;

use App\DTOs\Stats\LatestBodyMetrics;
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
     * Chacun est une fermeture, qu'Inertia ne résout que si sa prop part dans
     * la réponse : la requête de la prop différée `deferredData`, qui rejoue le
     * contrôleur, relisait sinon la pesée et la bibliothèque pour les jeter
     * aussitôt (#2148, étape 3a). Les trois chiffres de la pesée viennent d'une
     * seule lecture du cache, faite par la première fermeture résolue. Aucune
     * ne lit l'horloge.
     *
     * @param  string  $period  La période demandée, telle qu'elle arrive de la requête (« 30j »).
     * @return array{
     *     latestWeight: \Closure(): (float|string|null),
     *     weightChange: \Closure(): float,
     *     bodyFat: \Closure(): (float|string|null),
     *     exercises: \Closure(): \Illuminate\Database\Eloquent\Collection<int, Exercise>,
     *     selectedPeriod: string
     * }
     */
    public function chiffresImmediats(User $user, string $period): array
    {
        $mesures = null;
        $dernieresMesures = function () use ($user, &$mesures): LatestBodyMetrics {
            return $mesures ??= $this->bodyStats->getLatestBodyMetrics($user);
        };

        return [
            'latestWeight' => fn (): float|string|null => $dernieresMesures()->latest_weight,
            'weightChange' => fn (): float => $dernieresMesures()->weight_change,
            'bodyFat' => fn (): float|string|null => $dernieresMesures()->latest_body_fat,
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
