<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Actions\Tools\Concerns\CalculatesWilksScore;
use App\Models\WilksScore;

final class UpdateWilksScoreAction
{
    use CalculatesWilksScore;

    /**
     * @param  array{body_weight: float, lifted_weight: float, gender: 'male'|'female', unit: 'kg'|'lbs'}  $data
     */
    public function execute(WilksScore $wilksScore, array $data): WilksScore
    {
        $bw = $data['body_weight'];
        $lifted = $data['lifted_weight'];
        $gender = $data['gender'];
        $unit = $data['unit'];

        // Convert to KG for calculation if necessary
        $bwKg = $unit === 'lbs' ? $bw / 2.20462 : $bw;
        $liftedKg = $unit === 'lbs' ? $lifted / 2.20462 : $lifted;

        $score = $this->calculateWilks($bwKg, $liftedKg, $gender);

        $wilksScore->update([
            'body_weight' => $bw,
            'lifted_weight' => $lifted,
            'gender' => $gender,
            'unit' => $unit,
            'score' => $score,
        ]);

        return $wilksScore;
    }
}
