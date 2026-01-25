<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tools\CreateMacroCalculationAction;
use App\Actions\Tools\UpdateMacroCalculationAction;
use App\Http\Requests\StoreMacroCalculationRequest;
use App\Http\Requests\UpdateMacroCalculationRequest;
use App\Http\Resources\MacroCalculationResource;
use App\Models\MacroCalculation;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MacroCalculationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MacroCalculation::class);

        $calculations = $this->user()->macroCalculations()
            ->orderBy('created_at', 'desc')
            ->paginate();

        return MacroCalculationResource::collection($calculations);
    }

    public function store(StoreMacroCalculationRequest $request, CreateMacroCalculationAction $action): MacroCalculationResource
    {
        $this->authorize('create', MacroCalculation::class);

        $calculation = $action->execute($this->user(), $request->validated());

        return new MacroCalculationResource($calculation);
    }

    public function show(MacroCalculation $macroCalculation): MacroCalculationResource
    {
        $this->authorize('view', $macroCalculation);

        return new MacroCalculationResource($macroCalculation);
    }

    public function update(UpdateMacroCalculationRequest $request, MacroCalculation $macroCalculation, UpdateMacroCalculationAction $action): MacroCalculationResource
    {
        $this->authorize('update', $macroCalculation);

        $calculation = $action->execute($macroCalculation, $request->validated());

        return new MacroCalculationResource($calculation);
    }

    public function destroy(MacroCalculation $macroCalculation): \Illuminate\Http\Response
    {
        $this->authorize('delete', $macroCalculation);

        $macroCalculation->delete();

        return response()->noContent();
    }
}
