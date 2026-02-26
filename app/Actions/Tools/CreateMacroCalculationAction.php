<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Actions\Tools\Concerns\CalculatesMacros;
use App\Models\MacroCalculation;
use App\Models\User;

final class CreateMacroCalculationAction
{
    use CalculatesMacros;

    /**
     * @param  array{gender: string, age: int, height: float, weight: float, activity_level: string, goal: string}  $data
     */
    public function execute(User $user, array $data): MacroCalculation
    {
        $results = $this->performCalculation($data);

        // Store multiplier instead of label
        $data['activity_level'] = self::MULTIPLIERS[$data['activity_level']];

        /** @var \App\Models\MacroCalculation $macroCalculation */
        $macroCalculation = $user->macroCalculations()->create(array_merge($data, $results));

        return $macroCalculation;
    }
}
