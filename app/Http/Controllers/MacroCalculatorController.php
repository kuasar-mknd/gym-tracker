<?php

namespace App\Http\Controllers;

use App\Models\MacroCalculation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MacroCalculatorController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of macro calculations.
     */
    public function index(): \Inertia\Response
    {
        $user = $this->user();

        $history = $user->macroCalculations()
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Tools/MacroCalculator', [
            'history' => $history,
        ]);
    }

    /**
     * Store a new macro calculation.
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'gender' => ['required', 'string', 'in:male,female'],
            'age' => ['required', 'integer', 'min:10', 'max:100'],
            'height' => ['required', 'numeric', 'min:50', 'max:300'], // cm
            'weight' => ['required', 'numeric', 'min:20', 'max:300'], // kg
            'activity_level' => ['required', 'string', 'in:sedentary,light,moderate,very,extra'],
            'goal' => ['required', 'string', 'in:cut,maintain,bulk'],
        ]);

        $results = $this->performCalculation($validated);

        // Store multiplier instead of label
        $multipliers = [
            'sedentary' => 1.2,
            'light' => 1.375,
            'moderate' => 1.55,
            'very' => 1.725,
            'extra' => 1.9,
        ];
        $validated['activity_level'] = $multipliers[$validated['activity_level']];

        $this->user()->macroCalculations()->create(array_merge($validated, $results));

        return redirect()->back();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MacroCalculation $macroCalculation): \Illuminate\Http\RedirectResponse
    {
        if ($macroCalculation->user_id !== $this->user()->id) {
            abort(403);
        }

        $macroCalculation->delete();

        return redirect()->back();
    }

    /**
     * Perform the macro calculation logic.
     */
    /**
     * @param  array{gender: string, age: int, height: float, weight: float, activity_level: string, goal: string}  $data
     * @return array<string, float|int>
     */
    protected function performCalculation(array $data): array
    {
        $multipliers = [
            'sedentary' => 1.2,
            'light' => 1.375,
            'moderate' => 1.55,
            'very' => 1.725,
            'extra' => 1.9,
        ];

        $bmr = $this->calculateBMR($data);
        $tdee = round($bmr * $multipliers[$data['activity_level']]);
        $targetCalories = $this->calculateTargetCalories($tdee, $data['goal'], $data['gender']);

        $macros = $this->calculateMacros($targetCalories, $data['weight']);

        return array_merge([
            'tdee' => $tdee,
            'target_calories' => $targetCalories,
        ], $macros);
    }

    /**
     * Calculate Basal Metabolic Rate using Mifflin-St Jeor formula.
     */
    /**
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
