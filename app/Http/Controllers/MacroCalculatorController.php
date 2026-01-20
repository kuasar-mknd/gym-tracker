<?php

namespace App\Http\Controllers;

use App\Actions\Tools\CreateMacroCalculationAction;
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

    public function store(Request $request, CreateMacroCalculationAction $createMacroCalculation)
    {
        $validated = $request->validate([
            'gender' => ['required', 'string', 'in:male,female'],
            'age' => ['required', 'integer', 'min:10', 'max:100'],
            'height' => ['required', 'numeric', 'min:50', 'max:300'], // cm
            'weight' => ['required', 'numeric', 'min:20', 'max:300'], // kg
            'activity_level' => ['required', 'string', 'in:sedentary,light,moderate,very,extra'],
            'goal' => ['required', 'string', 'in:cut,maintain,bulk'],
        ]);

        $createMacroCalculation->execute($request->user(), $validated);

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
