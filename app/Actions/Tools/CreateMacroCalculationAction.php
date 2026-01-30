<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Models\MacroCalculation;
use App\Models\User;

class CreateMacroCalculationAction
{
    private const MULTIPLIERS = [
        'sedentary' => 1.2,
        'light' => 1.375,
        'moderate' => 1.55,
        'very' => 1.725,
        'extra' => 1.9,
    ];

    /**
     * @param  array{gender: string, age: int, height: float, weight: float, activity_level: string, goal: string}  $data
     */
    public function execute(User $user, array $data): MacroCalculation
    {
        $results = $this->performCalculation($data);

        // Store multiplier instead of label
        $data['activity_level'] = self::MULTIPLIERS[$data['activity_level']];

        /** @var MacroCalculation */
        return $user->macroCalculations()->create(array_merge($data, $results));
    }

    /**
     * Perform the macro calculation logic.
     *
     * @param  array{gender: string, age: int, height: float, weight: float, activity_level: string, goal: string}  $data
     * @return array<string, float|int>
     */
    protected function performCalculation(array $data): array
    {
        $bmr = $this->calculateBMR($data);
        $tdee = round($bmr * self::MULTIPLIERS[$data['activity_level']]);
        $targetCalories = $this->calculateTargetCalories($tdee, $data['goal'], $data['gender']);

        $macros = $this->calculateMacros($targetCalories, $data['weight']);

        return array_merge([
            'tdee' => $tdee,
            'target_calories' => $targetCalories,
        ], $macros);
    }

    /**
     * Calculate Basal Metabolic Rate using Mifflin-St Jeor formula.
     *
     * @param  array{gender: string, age: int, height: float, weight: float, activity_level: string, goal: string}  $data
     */
    protected function calculateBMR(array $data): float
    {
        $bmr = (10 * $data['weight']) + (6.25 * $data['height']) - (5 * $data['age']);

        return $data['gender'] === 'male' ? $bmr + 5 : $bmr - 161;
    }

    /**
     * Calculate target calories based on goal and gender.
     */
    protected function calculateTargetCalories(float $tdee, string $goal, string $gender): float
    {
        $target = match ($goal) {
            'cut' => $tdee - 500,
            'bulk' => $tdee + 300,
            default => $tdee,
        };

        $minimum = $gender === 'male' ? 1500 : 1200;

        return max($target, $minimum);
    }

    /**
     * Calculate macros based on target calories and weight.
     *
     * @return array<string, float|int>
     */
    protected function calculateMacros(float $targetCalories, float $weight): array
    {
        $protein = round($weight * 2);
        $fat = round($weight * 0.9);

        $remainingCalories = $targetCalories - ($protein * 4 + $fat * 9);

        if ($remainingCalories < 0) {
            $fat = max(30, round(($targetCalories - ($protein * 4)) / 9));
            $remainingCalories = $targetCalories - ($protein * 4 + $fat * 9);
        }

        return [
            'protein' => $protein,
            'fat' => $fat,
            'carbs' => max(0, round($remainingCalories / 4)),
        ];
    }
}
