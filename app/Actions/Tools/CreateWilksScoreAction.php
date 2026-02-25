<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Models\User;
use App\Models\WilksScore;

final class CreateWilksScoreAction
{
    /**
     * @param  array{body_weight: float, lifted_weight: float, gender: string, unit: string}  $data
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

        $score = $this->calculateWilks($bwKg, $liftedKg, $gender);

        /** @var \App\Models\WilksScore */
        return $user->wilksScores()->create([
            'body_weight' => $bw,
            'lifted_weight' => $lifted,
            'gender' => $gender,
            'unit' => $unit,
            'score' => $score,
        ]);
    }

    private function calculateWilks(float $bw, float $lifted, string $gender): float
    {
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

        $coeff = 500 / ($a + $b * $bw + $c * $bw ** 2 + $d * $bw ** 3 + $e * $bw ** 4 + $f * $bw ** 5);

        return round($lifted * $coeff, 2);
    }
}
