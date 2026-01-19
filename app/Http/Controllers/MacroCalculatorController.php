<?php

namespace App\Http\Controllers;

use App\Models\MacroCalculation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class MacroCalculatorController extends Controller
{
    use AuthorizesRequests;

    public function index()
    {
        $user = Auth::user();

        $history = $user->macroCalculations()
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Tools/MacroCalculator', [
            'history' => $history,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'gender' => ['required', 'string', 'in:male,female'],
            'age' => ['required', 'integer', 'min:10', 'max:100'],
            'height' => ['required', 'numeric', 'min:50', 'max:300'], // cm
            'weight' => ['required', 'numeric', 'min:20', 'max:300'], // kg
            'activity_level' => ['required', 'string', 'in:sedentary,light,moderate,very,extra'],
            'goal' => ['required', 'string', 'in:cut,maintain,bulk'],
        ]);

        $gender = $validated['gender'];
        $age = $validated['age'];
        $height = $validated['height'];
        $weight = $validated['weight'];
        $goal = $validated['goal'];

        $multipliers = [
            'sedentary' => 1.2,
            'light' => 1.375,
            'moderate' => 1.55,
            'very' => 1.725,
            'extra' => 1.9,
        ];
        $activityLevel = $multipliers[$validated['activity_level']];

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

        $request->user()->macroCalculations()->create([
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

        return redirect()->back();
    }

    public function destroy(MacroCalculation $macroCalculation)
    {
        if ($macroCalculation->user_id !== Auth::id()) {
            abort(403);
        }

        $macroCalculation->delete();

        return redirect()->back();
    }
}
