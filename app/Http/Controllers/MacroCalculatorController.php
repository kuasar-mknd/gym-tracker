<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tools\CreateMacroCalculationAction;
use App\Http\Requests\Api\StoreMacroCalculationRequest;
use App\Models\MacroCalculation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Inertia\Inertia;

class MacroCalculatorController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of macro calculations.
     */
    public function index(): \Inertia\Response
    {
        $this->authorize('viewAny', MacroCalculation::class);

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
    public function store(StoreMacroCalculationRequest $request, CreateMacroCalculationAction $createMacroCalculationAction): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', MacroCalculation::class);

        /** @var array{gender: string, age: int, height: float, weight: float, activity_level: string, goal: string} $validated */
        $validated = $request->validated();

        $createMacroCalculationAction->execute($this->user(), $validated);

        return redirect()->back();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MacroCalculation $macroCalculation): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $macroCalculation);

        $macroCalculation->delete();

        return redirect()->back();
    }
}
