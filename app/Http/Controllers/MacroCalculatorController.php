<?php

namespace App\Http\Controllers;

use App\Actions\Tools\CreateMacroCalculationAction;
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
    public function store(Request $request, CreateMacroCalculationAction $createMacroCalculationAction): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'gender' => ['required', 'string', 'in:male,female'],
            'age' => ['required', 'integer', 'min:10', 'max:100'],
            'height' => ['required', 'numeric', 'min:50', 'max:300'], // cm
            'weight' => ['required', 'numeric', 'min:20', 'max:300'], // kg
            'activity_level' => ['required', 'string', 'in:sedentary,light,moderate,very,extra'],
            'goal' => ['required', 'string', 'in:cut,maintain,bulk'],
        ]);

        $createMacroCalculationAction->execute($this->user(), $validated);

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
}
