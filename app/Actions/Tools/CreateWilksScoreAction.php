<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Models\User;
use App\Models\WilksScore;

final class CreateWilksScoreAction
{
    /**
     * La plage de poids de corps, en kilos, sur laquelle le polynôme de Wilks
     * est défini : les bornes des implémentations de référence.
     *
     * Au-delà, son dénominateur finit par changer de signe (vers 13,5 et
     * 283 kg chez l'homme, vers 208 kg chez la femme) et le coefficient
     * remonte avant même d'y arriver. La validation accepte de 1 à 500, en kg
     * comme en lbs : le poids est donc ramené à la borne la plus proche, comme
     * le fait le calcul côté client (`resources/js/Utils/formulas.js`).
     *
     * @var array{male: array{0: float, 1: float}, female: array{0: float, 1: float}}
     */
    private const array PLAGE_DU_POIDS_DE_CORPS = [
        'male' => [40.0, 201.9],
        'female' => [26.51, 154.53],
    ];

    /**
     * @param  array{
     *     body_weight: float,
     *     lifted_weight: float,
     *     gender: string,
     *     unit: string
     * }  $data
     */
    public function execute(User $user, array $data): WilksScore
    {
        $bw = $data['body_weight'];
        $lifted = $data['lifted_weight'];
        $gender = $data['gender'];
        $unit = $data['unit'];

        // Convert to KG for calculation if necessary
        $bwKg = $unit === 'lbs' ? $bw / 2.20462 : $bw;
        $liftedKg = $unit === 'lbs' ? $lifted / 2.20462 : $lifted;

        $scoreValue = $this->calculateWilks($bwKg, $liftedKg, $gender);

        /** @var WilksScore */
        return $user->wilksScores()->create([
            'body_weight' => $bw,
            'lifted_weight' => $lifted,
            'gender' => $gender,
            'unit' => $unit,
            'score' => $scoreValue,
        ]);
    }

    private function calculateWilks(float $bw, float $lifted, string $gender): float
    {
        [$minimum, $maximum] = self::PLAGE_DU_POIDS_DE_CORPS[$gender === 'male' ? 'male' : 'female'];
        $bw = min(max($bw, $minimum), $maximum);

        if ($gender === 'male') {
            $a = -216.0475144;
            $b = 16.2606339;
            $c = -0.002388645;
            $d = -0.00113732;
            $e = 7.01863E-06;
            $f = -1.291E-08;
        } else {
            $a = 594.31747775582;
            $b = -27.23842536447;
            $c = 0.82112226871;
            $d = -0.00930733913;
            $e = 4.731582E-05;
            $f = -9.054E-08;
        }

        $val = $a + $b * $bw + $c * $bw ** 2 + $d * $bw ** 3 + $e * $bw ** 4 + $f * $bw ** 5;
        $coeff = 500 / $val;

        return round($lifted * $coeff, 2);
    }
}
