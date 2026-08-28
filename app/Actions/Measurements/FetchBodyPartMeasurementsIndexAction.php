<?php

declare(strict_types=1);

namespace App\Actions\Measurements;

use App\Models\BodyPartMeasurement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FetchBodyPartMeasurementsIndexAction
{
    /**
     * @return array{latestMeasurements: Collection<int, array{part: string, current: float, unit: string, date: non-falsy-string, diff: float}>, commonParts: array<int, string>}
     */
    public function execute(User $user): array
    {
        /*
         * Deux mesures par partie, chacune prise par l'index.
         *
         * Un `ROW_NUMBER() OVER (PARTITION BY part ...)` occupait cette place,
         * sous un commentaire affirmant eviter « loading full history ». Il
         * evitait bien le N+1, mais pas la lecture : le rang doit etre calcule
         * sur TOUTES les lignes avant que le `rn <= 2` de la requete englobante
         * s'applique. Mesure faite : 4 992 lignes lues pour une table de 2 496,
         * afin d'afficher huit chiffres.
         *
         * L'union rend une branche par partie, chacune bornee a deux lignes et
         * servie par `(user_id, part, measured_at)`. Les lignes lues passent de
         * 4 992 a 2 496, et ce qui reste est une lecture d'index seule, sans tri
         * ni table temporaire.
         *
         * Ce reste — le `DISTINCT part` — grandit encore avec l'historique :
         * `part` est du texte libre, la liste des parties ne peut donc pas etre
         * connue d'avance. Le rendre constant demanderait une table de
         * dimension entretenue a l'ecriture, ce qui coute plus cher que le
         * balayage d'index qu'elle eviterait.
         */
        $parties = BodyPartMeasurement::query()
            ->where('user_id', $user->id)
            ->distinct()
            ->orderBy('part')
            ->pluck('part');

        $premiere = $parties->shift();

        if (! is_string($premiere)) {
            /** @var Collection<int, array{part: string, current: float, unit: string, date: non-falsy-string, diff: float}> $vide */
            $vide = collect();

            return ['latestMeasurements' => $vide, 'commonParts' => $this->getCommonParts()];
        }

        $union = $this->deuxDernieres($user->id, $premiere);

        foreach ($parties as $partie) {
            if (is_string($partie)) {
                $union->unionAll($this->deuxDernieres($user->id, $partie));
            }
        }

        $measurements = $union->get();

        // Group by part, get latest for card display
        $latestMeasurements = $measurements
            ->groupBy('part')
            ->map(function ($group): array {
                /** @var BodyPartMeasurement $latest */
                $latest = $group->first();
                /** @var BodyPartMeasurement|null $previous */
                $previous = $group->skip(1)->first();

                $courante = (float) $latest->value;

                return [
                    'part' => $latest->part,
                    'current' => $courante,
                    'unit' => $latest->unit,
                    'date' => Carbon::parse($latest->measured_at)->format('Y-m-d'),
                    'diff' => $previous !== null ? round($courante - (float) $previous->value, 2) : 0.0,
                ];
            })->values();

        return [
            'latestMeasurements' => $latestMeasurements,
            'commonParts' => $this->getCommonParts(),
        ];
    }

    /**
     * @return array<int, string>
     */
    /**
     * @return \Illuminate\Database\Eloquent\Builder<BodyPartMeasurement>
     */
    private function deuxDernieres(int $userId, string $partie): \Illuminate\Database\Eloquent\Builder
    {
        return BodyPartMeasurement::query()
            ->where('user_id', $userId)
            ->where('part', $partie)
            ->orderByDesc('measured_at')
            ->limit(2);
    }

    /**
     * @return array<int, string>
     */
    private function getCommonParts(): array
    {
        return [
            'Neck',
            'Shoulders',
            'Chest',
            'Biceps L',
            'Biceps R',
            'Forearm L',
            'Forearm R',
            'Waist',
            'Hips',
            'Thigh L',
            'Thigh R',
            'Calf L',
            'Calf R',
        ];
    }
}
