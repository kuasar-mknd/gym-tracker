<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tools\CreateMacroCalculationAction;
use App\Actions\Tools\UpdateMacroCalculationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMacroCalculationRequest;
use App\Http\Requests\UpdateMacroCalculationRequest;
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
        $calculations = $this->user()->macroCalculations()
            ->orderByDesc('created_at')
            ->paginate(20);

        return MacroCalculationResource::collection($calculations);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreMacroCalculationRequest $request, CreateMacroCalculationAction $action): MacroCalculationResource
    {
        /** @var array{gender: string, age: int, height: float, weight: float, activity_level: string, goal: string} $validated */
        $validated = $request->validated();

        $calculation = $action->execute($this->user(), $validated);

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
    public function update(UpdateMacroCalculationRequest $request, MacroCalculation $macroCalculation, UpdateMacroCalculationAction $action): MacroCalculationResource
    {
        $this->authorize('update', $macroCalculation);

        /** @var array{gender: string, age: int, height: float, weight: float, activity_level: string, goal: string} $validated */
        $validated = $request->validated();

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
