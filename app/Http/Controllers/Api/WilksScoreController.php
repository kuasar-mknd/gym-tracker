<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tools\CreateWilksScoreAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreWilksScoreRequest;
use App\Http\Requests\Api\UpdateWilksScoreRequest;
use App\Http\Resources\WilksScoreResource;
use App\Models\WilksScore;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class WilksScoreController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WilksScore::class);

        $scores = $this->user()->wilksScores()
            ->latest()
            ->paginate();

        return WilksScoreResource::collection($scores);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreWilksScoreRequest $request, CreateWilksScoreAction $createWilksScore): WilksScoreResource
    {
        $this->authorize('create', WilksScore::class);

        /*
         * Par la meme action que le chemin web.
         *
         * Le controleur enregistrait `$validated` tel quel, `score` compris :
         * la valeur venait du client au lieu d'etre calculee, et les unites
         * n'etaient pas converties (#1378).
         */
        /** @var array{body_weight: float, lifted_weight: float, gender: 'male'|'female', unit: 'kg'|'lbs'} $validated */
        $validated = $request->validated();

        $score = $createWilksScore->execute($this->user(), $validated);

        return new WilksScoreResource($score);
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
    public function update(UpdateWilksScoreRequest $request, WilksScore $wilksScore): WilksScoreResource
    {
        $this->authorize('update', $wilksScore);

        $wilksScore->update($request->validated());

        return new WilksScoreResource($wilksScore);
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
