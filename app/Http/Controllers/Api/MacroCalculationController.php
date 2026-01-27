<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tools\CreateMacroCalculationAction;
use App\Actions\Tools\UpdateMacroCalculationAction;
use App\Http\Requests\MacroCalculationStoreRequest;
use App\Http\Requests\MacroCalculationUpdateRequest;
use App\Http\Resources\MacroCalculationResource;
use App\Models\MacroCalculation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;

class MacroCalculationController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/macro-calculations',
        summary: 'Get list of macro calculations',
        tags: ['Macro Calculations']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MacroCalculation::class);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        $calculations = $user->macroCalculations()
            ->orderBy('created_at', 'desc')
            ->paginate();

        return MacroCalculationResource::collection($calculations);
    }

    public function store(MacroCalculationStoreRequest $request, CreateMacroCalculationAction $createMacroCalculationAction): MacroCalculationResource
    {
        $this->authorize('create', MacroCalculation::class);

        /** @var \App\Models\User $user */
        $user = $request->user();

        $macroCalculation = $createMacroCalculationAction->execute($user, $request->validated());

        return new MacroCalculationResource($macroCalculation);
    }

    public function show(MacroCalculation $macroCalculation): MacroCalculationResource
    {
        $this->authorize('view', $macroCalculation);

        return new MacroCalculationResource($macroCalculation);
    }

    public function update(MacroCalculationUpdateRequest $request, MacroCalculation $macroCalculation, UpdateMacroCalculationAction $updateMacroCalculationAction): MacroCalculationResource
    {
        $this->authorize('update', $macroCalculation);

        $updatedMacroCalculation = $updateMacroCalculationAction->execute($macroCalculation, $request->validated());

        return new MacroCalculationResource($updatedMacroCalculation);
    }

    public function destroy(MacroCalculation $macroCalculation): Response
    {
        $this->authorize('delete', $macroCalculation);

        $macroCalculation->delete();

        return response()->noContent();
    }
}
