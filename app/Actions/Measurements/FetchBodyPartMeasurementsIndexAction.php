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
     * @return array{latestMeasurements: Collection<int, array{part: string, current: float, unit: string, date: non-falsy-string, diff: float}>, commonParts: array<int, string>}
     */
    public function execute(User $user): array
    {
        /** @var Collection<int, array{part: string, current: float, unit: string, date: non-falsy-string, diff: float}> $latestMeasurements */
        $latestMeasurements = collect();
        $curseur = '';

        for ($i = 0; $i < self::PARTIES_MAX; $i++) {
            $lot = $this->partieSuivante($user->id, $curseur);
            $derniere = $lot->first();

            if ($derniere === null) {
                break;
            }

            $curseur = $derniere->part;
            $precedente = $lot->get(1);
            $courante = (float) $derniere->value;

            $latestMeasurements->push([
                'part' => $derniere->part,
                'current' => $courante,
                'unit' => $derniere->unit,
                'date' => Carbon::parse($derniere->measured_at)->format('Y-m-d'),
                'diff' => $precedente instanceof BodyPartMeasurement ? round($courante - (float) $precedente->value, 2) : 0.0,
            ]);
        }

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
    private function partieSuivante(int $userId, string $curseur): Collection
    {
        return BodyPartMeasurement::query()
            ->where('user_id', $userId)
            ->where('part', '=', fn (QueryBuilder $suivante) => $suivante
                ->selectRaw('min(part)')
                ->from('body_part_measurements')
                ->where('user_id', $userId)
                ->where('part', '>', $curseur))
            ->orderByDesc('measured_at')
            ->limit(2)
            ->get();
    }

    /**
     * @return array<int, string>
     */
    private function getCommonParts(): array
    {
        return \App\Models\BodyPartMeasurement::COMMON_PARTS;
    }
}
