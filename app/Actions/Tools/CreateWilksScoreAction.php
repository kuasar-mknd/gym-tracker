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

        $scoreValue = self::score($bw, $lifted, $gender, $unit);

        /** @var WilksScore */
        return $user->wilksScores()->create([
            'body_weight' => $bw,
            'lifted_weight' => $lifted,
            'gender' => $gender,
            'unit' => $unit,
            'score' => $scoreValue,
        ]);
    }

    /**
     * Le score de Wilks d'un total, à partir des valeurs telles qu'elles sont
     * saisies et enregistrées.
     *
     * Public pour la migration qui recalcule les scores enregistrés hors de la
     * plage avant que le poids ne soit borné : les deux suivent ainsi le même
     * calcul.
     *
     * @param  float  $poidsDeCorps  Le poids de corps, dans l'unité saisie.
     * @param  float  $total  Le total soulevé, dans l'unité saisie.
     * @param  string  $genre  `male`, ou toute autre valeur pour la formule féminine.
     * @param  string  $unite  `kg` ou `lbs`.
     */
    public static function score(float $poidsDeCorps, float $total, string $genre, string $unite): float
    {
        return self::calculateWilks(self::enKilos($poidsDeCorps, $unite), self::enKilos($total, $unite), $genre);
    }

    /**
     * Le poids de corps saisi, une fois converti en kilos, sort de la plage de
     * la formule : le score enregistré avant que le poids ne soit borné était
     * faux pour lui seul (#1959).
     *
     * @param  float  $poidsDeCorps  Le poids de corps, dans l'unité saisie.
     * @param  string  $genre  `male`, ou toute autre valeur pour la formule féminine.
     * @param  string  $unite  `kg` ou `lbs`.
     */
    public static function poidsDeCorpsHorsDeLaPlage(float $poidsDeCorps, string $genre, string $unite): bool
    {
        [$minimum, $maximum] = self::plage($genre);
        $kilos = self::enKilos($poidsDeCorps, $unite);

        return $kilos < $minimum || $kilos > $maximum;
    }

    private static function enKilos(float $valeur, string $unite): float
    {
        return $unite === 'lbs' ? $valeur / 2.20462 : $valeur;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private static function plage(string $genre): array
    {
        return self::PLAGE_DU_POIDS_DE_CORPS[$genre === 'male' ? 'male' : 'female'];
    }

    private static function calculateWilks(float $bw, float $lifted, string $gender): float
    {
        [$minimum, $maximum] = self::plage($gender);
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
