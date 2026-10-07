<?php

declare(strict_types=1);

namespace App\Actions\Measurements;

use App\Models\BodyPartMeasurement;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FetchBodyPartMeasurementsIndexAction
{
    /**
     * Au-dela, la page afficherait autant de cartes que de parties : la borne
     * tient le cout de la page autant que celui des requetes.
     */
    private const int PARTIES_MAX = 50;

    /**
     * Le nom d'une carte dépend des autres parties du compte : une « Taille »
     * saisie à la main se distingue de `Waist`, qui s'affiche aussi « Taille »
     * (#1974). Les parties sont donc toutes relevées avant d'être nommées.
     *
     * @return array{latestMeasurements: Collection<int, array{part: string, label: string, current: float, unit: string, date: non-falsy-string, diff: float}>, commonParts: list<array{value: string, label: string}>}
     */
    public function execute(User $user): array
    {
        /** @var list<array{0: BodyPartMeasurement, 1: BodyPartMeasurement|null}> $parties */
        $parties = [];
        $curseur = '';

        for ($i = 0; $i < self::PARTIES_MAX; $i++) {
            $lot = $this->partieSuivante($user->id, $curseur);
            $derniere = $lot->first();

            if ($derniere === null) {
                break;
            }

            $curseur = $derniere->part;
            $parties[] = [$derniere, $lot->get(1)];
        }

        $partiesDuCompte = array_map(static fn (array $partie): string => $partie[0]->part, $parties);

        /** @var Collection<int, array{part: string, label: string, current: float, unit: string, date: non-falsy-string, diff: float}> $latestMeasurements */
        $latestMeasurements = collect($parties)->map(static function (array $partie) use ($partiesDuCompte): array {
            [$derniere, $precedente] = $partie;
            $courante = (float) $derniere->value;

            return [
                'part' => $derniere->part,
                'label' => BodyPartMeasurement::libelleParmi($derniere->part, $partiesDuCompte),
                'current' => $courante,
                'unit' => $derniere->unit,
                'date' => Carbon::parse($derniere->measured_at)->format('Y-m-d'),
                'diff' => $precedente instanceof BodyPartMeasurement ? round($courante - (float) $precedente->value, 2) : 0.0,
            ];
        });

        return [
            'latestMeasurements' => $latestMeasurements,
            'commonParts' => $this->getCommonParts(),
        ];
    }

    /**
     * Les deux dernieres mesures de la premiere partie apres `$curseur`.
     *
     * Le `min(part)` corelle est ce qui rend l'enumeration constante : il forme
     * une plage sur `(user_id, part, measured_at)` et s'arrete a la premiere
     * entree. Un `order by part limit 1` equivalent ne le fait pas — MySQL se
     * positionne alors sur `user_id` seul et balaie jusqu'a trouver.
     *
     * @return Collection<int, BodyPartMeasurement>
     */
    private function partieSuivante(int $idUtilisateur, string $curseur): Collection
    {
        return BodyPartMeasurement::query()
            ->where('user_id', $idUtilisateur)
            ->where('part', '=', fn (QueryBuilder $suivante) => $suivante
                ->selectRaw('min(part)')
                ->from('body_part_measurements')
                ->where('user_id', $idUtilisateur)
                ->where('part', '>', $curseur))
            ->orderByDesc('measured_at')
            ->limit(2)
            ->get();
    }

    /**
     * Les parties proposées, avec le nom que la page affiche.
     *
     * @return list<array{value: string, label: string}>
     */
    private function getCommonParts(): array
    {
        return array_map(
            static fn (string $partie): array => ['value' => $partie, 'label' => BodyPartMeasurement::libelle($partie)],
            BodyPartMeasurement::COMMON_PARTS,
        );
    }
}
