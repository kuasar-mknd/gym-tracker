<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Actions\Tools\Concerns\CalculatesMacros;
use App\Models\MacroCalculation;

final class UpdateMacroCalculationAction
{
    use CalculatesMacros;

    /**
     * @param  array{gender: string, age: int, height: float, weight: float, activity_level: string, goal: string}  $data
     */
    public function execute(MacroCalculation $macroCalculation, array $data): MacroCalculation
    {
        $results = $this->performCalculation($data);

        // Store multiplier instead of label
        $data['activity_level'] = self::MULTIPLIERS[$data['activity_level']];

        $macroCalculation->update(array_merge($data, $results));

        return $macroCalculation;
    }
}
