<?php

declare(strict_types=1);

namespace App\Actions\Corps;

use App\Actions\Measurements\FetchBodyPartMeasurementsIndexAction;
use App\DTOs\Stats\BodyFatHistoryPoint;
use App\DTOs\Stats\LatestBodyMetrics;
use App\DTOs\Stats\WeightHistoryPoint;
use App\Models\User;
use App\Services\Stats\BodyStatsService;
use Illuminate\Support\Collection;

/**
 * Ce que la page Corps réunit : le poids et la masse grasse, que la racine de
 * Progrès montrait, et les dernières mensurations.
 */
class FetchCorpsAction
{
    /**
     * La profondeur des courbes du poids et de la masse grasse, en jours.
     */
    public const int JOURS_DES_COURBES = 90;

    public function __construct(
        protected BodyStatsService $bodyStats,
        protected FetchBodyPartMeasurementsIndexAction $mensurations,
    ) {
    }

    /**
     * Chaque prop est une fermeture, qu'Inertia ne résout que si elle part
     * dans la réponse : la requête de la prop différée `bodyStats`, qui rejoue
     * le contrôleur, ne relit ni la pesée ni les mensurations
     * (`.ai/rules/controllers.md`). Les trois chiffres de la pesée viennent
     * d'une seule lecture du cache, faite par la première fermeture résolue.
     *
     * @return array{
     *     latestWeight: \Closure(): (float|string|null),
     *     weightChange: \Closure(): float,
     *     bodyFat: \Closure(): (float|string|null),
     *     latestMeasurements: \Closure(): Collection<int, array{part: string, label: string, current: float, unit: string, date: non-falsy-string, diff: float}>,
     *     bodyStats: \Closure(): array{weightHistory: array<int, WeightHistoryPoint>, bodyFatHistory: array<int, BodyFatHistoryPoint>}
     * }
     */
    public function execute(User $user): array
    {
        $mesures = null;
        $dernieresMesures = function () use ($user, &$mesures): LatestBodyMetrics {
            return $mesures ??= $this->bodyStats->getLatestBodyMetrics($user);
        };

        return [
            'latestWeight' => fn (): float|string|null => $dernieresMesures()->latest_weight,
            'weightChange' => fn (): float => $dernieresMesures()->weight_change,
            'bodyFat' => fn (): float|string|null => $dernieresMesures()->latest_body_fat,
            'latestMeasurements' => fn (): Collection => $this->mensurations->execute($user)['latestMeasurements'],
            'bodyStats' => fn (): array => $this->bodyStats->getBodyProgressOverview($user, self::JOURS_DES_COURBES),
        ];
    }
}
