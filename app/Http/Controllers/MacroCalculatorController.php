<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tools\CreateMacroCalculationAction;
use App\Http\Requests\Api\StoreMacroCalculationRequest;
use App\Models\MacroCalculation;
use Inertia\Inertia;

class MacroCalculatorController extends Controller
{
    public function index(): \Inertia\Response
    {
        $this->authorize('viewAny', MacroCalculation::class);

        $user = $this->user();

        $history = $user->macroCalculations()
            // Borné à 100, comme `BodyMeasurementController` et
            // `DailyJournalController` : l'historique complet partait dans la
            // réponse, et il grandit à chaque usage.
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return Inertia::render('Tools/MacroCalculator', [
            'history' => $history,
        ]);
    }

    public function store(StoreMacroCalculationRequest $request, CreateMacroCalculationAction $createMacroCalculationAction): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', MacroCalculation::class);

        /** @var array{gender: string, age: int, height: float, weight: float, activity_level: string, goal: string} $validated */
        $validated = $request->validated();

        $createMacroCalculationAction->execute($this->user(), $validated);

        return redirect()->back();
    }

    public function destroy(MacroCalculation $macroCalculation): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $macroCalculation);

        $macroCalculation->delete();

        return redirect()->back();
    }
}
