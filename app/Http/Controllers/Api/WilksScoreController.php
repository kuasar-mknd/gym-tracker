<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tools\CreateWilksScoreAction;
use App\Actions\Tools\UpdateWilksScoreAction;
use App\Http\Requests\StoreWilksScoreRequest;
use App\Http\Requests\UpdateWilksScoreRequest;
use App\Http\Resources\WilksScoreResource;
use App\Models\WilksScore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class WilksScoreController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $scores = $this->user()->wilksScores()
            ->orderByDesc('created_at')
            ->paginate(20);

        return WilksScoreResource::collection($scores);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreWilksScoreRequest $request, CreateWilksScoreAction $action): WilksScoreResource
    {
        /** @var array{body_weight: float, lifted_weight: float, gender: string, unit: string} $validated */
        $validated = $request->validated();

        $wilksScore = $action->execute($this->user(), $validated);

        return new WilksScoreResource($wilksScore);
    }

    /**
     * Display the specified resource.
     */
    public function show(WilksScore $wilksScore): WilksScoreResource
    {
        $this->authorize('view', $wilksScore);

        return new WilksScoreResource($wilksScore);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateWilksScoreRequest $request, WilksScore $wilksScore, UpdateWilksScoreAction $action): WilksScoreResource
    {
        $this->authorize('update', $wilksScore);

        /** @var array{body_weight: float, lifted_weight: float, gender: string, unit: string} $validated */
        $validated = $request->validated();

        $updated = $action->execute($wilksScore, $validated);

        return new WilksScoreResource($updated);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(WilksScore $wilksScore): Response
    {
        $this->authorize('delete', $wilksScore);

        $wilksScore->delete();

        return response()->noContent();
    }
}
