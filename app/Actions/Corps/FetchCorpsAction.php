<?php

declare(strict_types=1);

namespace App\Actions\Corps;

use App\Actions\Measurements\FetchBodyPartMeasurementsIndexAction;
use App\DTOs\Stats\BodyFatHistoryPoint;
use App\DTOs\Stats\LatestBodyMetrics;
use App\DTOs\Stats\WeightHistoryPoint;
use App\Models\BodyPartMeasurement;
use App\Models\User;
use App\Services\Stats\BodyStatsService;
use Illuminate\Support\Collection;

/**
 * Ce que la page Corps réunit : le poids et la masse grasse, que la racine de
 * Progrès montrait, et les dernières mensurations.
 *
 * @phpstan-type Cote array{cote: string, part: string, label: string, current: float, unit: string, date: string, diff: float}
 * @phpstan-type Ligne array{part: string, label: string, current: float, unit: string, date: string, diff: float}|array{famille: string, label: string, cotes: list<Cote>}
 */
class FetchCorpsAction
{
    /**
     * La profondeur des courbes du poids et de la masse grasse, en jours.
     */
    public const int JOURS_DES_COURBES = 90;

    /**
     * Combien de lignes la carte des mensurations montre ; le reste est derrière « Toutes les mensurations ».
     */
    public const int MENSURATIONS_AFFICHEES = 6;

    /**
     * L'ordre où la carte les range : un nom commence par l'un des débuts de son rang.
     *
     * @var list<list<string>>
     */
    private const array ORDRE_DES_MENSURATIONS = [
        ['waist', 'taille'],
        ['chest', 'poitrine'],
        ['hips', 'hanches'],
        ['thigh', 'cuisse'],
        ['biceps'],
        ['calf', 'mollet'],
    ];

    public function __construct(
        protected BodyStatsService $bodyStats,
        protected FetchBodyPartMeasurementsIndexAction $mensurations,
    ) {
    }

    /**
     * Chaque prop est une fermeture (sauf `joursDesCourbes`, une constante), qu'Inertia ne résout que si elle part
     * dans la réponse : la requête de la prop différée `bodyStats`, qui rejoue
     * le contrôleur, ne relit ni la pesée ni les mensurations
     * (`.ai/rules/controllers.md`). Les trois chiffres de la pesée viennent
     * d'une seule lecture du cache, faite par la première fermeture résolue.
     *
     * @return array{
     *     joursDesCourbes: int,
     *     latestWeight: \Closure(): (float|string|null),
     *     weightChange: \Closure(): float,
     *     bodyFat: \Closure(): (float|string|null),
     *     latestMeasurements: \Closure(): Collection<int, Ligne>,
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
            'joursDesCourbes' => self::JOURS_DES_COURBES,
            'latestWeight' => fn (): float|string|null => $dernieresMesures()->latest_weight,
            'weightChange' => fn (): float => $dernieresMesures()->weight_change,
            'bodyFat' => fn (): float|string|null => $dernieresMesures()->latest_body_fat,
            'latestMeasurements' => fn (): Collection => $this->lesPlusUtiles($this->mensurations->execute($user)['latestMeasurements']),
            'bodyStats' => fn (): array => $this->bodyStats->getBodyProgressOverview($user, self::JOURS_DES_COURBES),
        ];
    }

    /**
     * Les lignes que la carte montre : une par famille à deux côtés, une par
     * autre partie, rangées par `ORDRE_DES_MENSURATIONS` et coupées après le
     * regroupement. Un côté absent n'a pas de pastille ; une partie hors de la
     * liste fermée `PAIRES` reste une ligne simple, telle que la page des
     * mensurations la donne.
     *
     * @param  Collection<int, array{part: string, label: string, current: float, unit: string, date: non-falsy-string, diff: float}>  $mesures
     * @return Collection<int, Ligne>
     */
    private function lesPlusUtiles(Collection $mesures): Collection
    {
        $familleDeChaquePartie = [];
        foreach (BodyPartMeasurement::PAIRES as $famille => $paire) {
            $familleDeChaquePartie[strtolower($paire['gauche'])] = $famille;
            $familleDeChaquePartie[strtolower($paire['droite'])] = $famille;
        }

        $parNom = $mesures->keyBy(static fn (array $mesure): string => strtolower($mesure['part']));
        $famillesFaites = [];
        /** @var list<Ligne> $lignes */
        $lignes = [];

        foreach ($mesures as $mesure) {
            $famille = $familleDeChaquePartie[strtolower($mesure['part'])] ?? null;

            if ($famille === null) {
                $lignes[] = $mesure;

                continue;
            }

            if (isset($famillesFaites[$famille])) {
                continue;
            }

            $famillesFaites[$famille] = true;
            $paire = BodyPartMeasurement::PAIRES[$famille];
            /** @var list<Cote> $cotes */
            $cotes = [];

            foreach (['gauche', 'droite'] as $cote) {
                $cotePresent = $parNom->get(strtolower($paire[$cote]));

                if ($cotePresent !== null) {
                    $cotes[] = ['cote' => $cote, ...$cotePresent];
                }
            }

            $lignes[] = ['famille' => $famille, 'label' => $paire['libelle'], 'cotes' => $cotes];
        }

        usort($lignes, fn (array $a, array $b): int => $this->rang($a) <=> $this->rang($b));

        /** @var list<Ligne> $retenues */
        $retenues = array_slice($lignes, 0, self::MENSURATIONS_AFFICHEES);

        return collect($retenues);
    }

    /**
     * @param  array{famille?: string, part?: string}  $ligne
     */
    private function rang(array $ligne): int
    {
        $nom = strtolower($ligne['famille'] ?? $ligne['part'] ?? '');

        foreach (self::ORDRE_DES_MENSURATIONS as $position => $debuts) {
            foreach ($debuts as $debut) {
                if (str_starts_with($nom, $debut)) {
                    return $position;
                }
            }
        }

        return count(self::ORDRE_DES_MENSURATIONS);
    }
}
