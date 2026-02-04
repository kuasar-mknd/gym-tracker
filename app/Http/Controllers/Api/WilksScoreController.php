<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tools\CreateWilksScoreAction;
use App\Actions\Tools\UpdateWilksScoreAction;
use App\Http\Requests\StoreWilksScoreRequest;
use App\Http\Requests\UpdateWilksScoreRequest;
use App\Http\Resources\WilksScoreResource;
use App\Models\WilksScore;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class WilksScoreController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WilksScore::class);

        $scores = $this->user()->wilksScores()
            ->orderByDesc('created_at')
            ->paginate();

        return WilksScoreResource::collection($scores);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreWilksScoreRequest $request, CreateWilksScoreAction $action): WilksScoreResource
    {
        $this->authorize('create', WilksScore::class);

        /** @var array{body_weight: float, lifted_weight: float, gender: 'male'|'female', unit: 'kg'|'lbs'} $validated */
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
        // Authorization is handled in FormRequest, but good to have double check or rely on it.
        // UpdateWilksScoreRequest calls $this->user()->can('update', ...)
        // But I will add it here for consistency if Request fails to authorize properly (e.g. if logic changes).
        // However, standard is relying on Request or adding it here. GoalController added it in Request AND comments it.
        // I will add it here explicitely as well just in case.
        $this->authorize('update', $wilksScore);

        /** @var array{body_weight: float, lifted_weight: float, gender: 'male'|'female', unit: 'kg'|'lbs'} $validated */
        $validated = $request->validated();

        $updatedScore = $action->execute($wilksScore, $validated);

        return new WilksScoreResource($updatedScore);
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
