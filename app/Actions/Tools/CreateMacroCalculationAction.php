<?php

namespace App\Actions\Tools;

use App\Models\MacroCalculation;
use App\Models\User;

class CreateMacroCalculationAction
{
    public function execute(User $user, array $data): MacroCalculation
    {
        $gender = $data['gender'];
        $age = $data['age'];
        $height = $data['height'];
        $weight = $data['weight'];
        $goal = $data['goal'];

        $multipliers = [
            'sedentary' => 1.2,
            'light' => 1.375,
            'moderate' => 1.55,
            'very' => 1.725,
            'extra' => 1.9,
        ];
        $activityLevel = $multipliers[$data['activity_level']];

        // 1. Calculate BMR (Mifflin-St Jeor)
        if ($gender === 'male') {
            $bmr = (10 * $weight) + (6.25 * $height) - (5 * $age) + 5;
        } else {
            $bmr = (10 * $weight) + (6.25 * $height) - (5 * $age) - 161;
        }

        // 2. Calculate TDEE
        $tdee = round($bmr * $activityLevel);

        // 3. Calculate Target Calories
        $targetCalories = $tdee;
        if ($goal === 'cut') {
            $targetCalories -= 500;
        } elseif ($goal === 'bulk') {
            $targetCalories += 300; // Moderate surplus
        }

        // Ensure healthy minimum
        if ($gender === 'male' && $targetCalories < 1500) {
            $targetCalories = 1500;
        }
        if ($gender === 'female' && $targetCalories < 1200) {
            $targetCalories = 1200;
        }

        // 4. Calculate Macros
        // Strategy: 2g Protein/kg, 0.8g Fat/kg, rest Carbs
        $protein = round($weight * 2); // 2g per kg
        $fat = round($weight * 0.9); // 0.9g per kg (middle ground)

        $caloriesFromProtein = $protein * 4;
        $caloriesFromFat = $fat * 9;

        $remainingCalories = $targetCalories - ($caloriesFromProtein + $caloriesFromFat);

        // If remaining is negative (rare but possible with low cal/high weight), adjust
        if ($remainingCalories < 0) {
            // Priority to protein, lower fat
            $fat = max(30, round(($targetCalories - $caloriesFromProtein) / 9));
            $remainingCalories = $targetCalories - ($caloriesFromProtein + ($fat * 9));
        }

        $carbs = max(0, round($remainingCalories / 4));

        return $user->macroCalculations()->create([
            'gender' => $gender,
            'age' => $age,
            'height' => $height,
            'weight' => $weight,
            'activity_level' => $activityLevel,
            'goal' => $goal,
            'tdee' => $tdee,
            'target_calories' => $targetCalories,
            'protein' => $protein,
            'fat' => $fat,
            'carbs' => $carbs,
        ]);
    }
}
