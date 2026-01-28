<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tools\CreateMacroCalculationAction;
use App\Actions\Tools\UpdateMacroCalculationAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\MacroCalculationResource;
use App\Models\MacroCalculation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class MacroCalculationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $calculations = $request->user()->macroCalculations()
            ->orderByDesc('created_at')
            ->paginate(20);

        return MacroCalculationResource::collection($calculations);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, CreateMacroCalculationAction $action): MacroCalculationResource
    {
        $validated = $request->validate([
            'gender' => ['required', 'string', 'in:male,female'],
            'age' => ['required', 'integer', 'min:10', 'max:100'],
            'height' => ['required', 'numeric', 'min:50', 'max:300'],
            'weight' => ['required', 'numeric', 'min:20', 'max:300'],
            'activity_level' => ['required', 'string', 'in:sedentary,light,moderate,very,extra'],
            'goal' => ['required', 'string', 'in:cut,maintain,bulk'],
        ]);

        $calculation = $action->execute($request->user(), $validated);

        return new MacroCalculationResource($calculation);
    }

    /**
     * Display the specified resource.
     */
    public function show(MacroCalculation $macroCalculation): MacroCalculationResource
    {
        $this->authorize('view', $macroCalculation);

        return new MacroCalculationResource($macroCalculation);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, MacroCalculation $macroCalculation, UpdateMacroCalculationAction $action): MacroCalculationResource
    {
        $this->authorize('update', $macroCalculation);

        $validated = $request->validate([
            'gender' => ['required', 'string', 'in:male,female'],
            'age' => ['required', 'integer', 'min:10', 'max:100'],
            'height' => ['required', 'numeric', 'min:50', 'max:300'],
            'weight' => ['required', 'numeric', 'min:20', 'max:300'],
            'activity_level' => ['required', 'string', 'in:sedentary,light,moderate,very,extra'],
            'goal' => ['required', 'string', 'in:cut,maintain,bulk'],
        ]);

        $updated = $action->execute($macroCalculation, $validated);

        return new MacroCalculationResource($updated);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MacroCalculation $macroCalculation): Response
    {
        $this->authorize('delete', $macroCalculation);

        $macroCalculation->delete();

        return response()->noContent();
    }
}
