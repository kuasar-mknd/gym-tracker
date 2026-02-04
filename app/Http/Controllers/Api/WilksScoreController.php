<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tools\CreateWilksScoreAction;
use App\Actions\Tools\UpdateWilksScoreAction;
use App\Http\Requests\StoreWilksScoreRequest;
use App\Http\Resources\WilksScoreResource;
use App\Models\WilksScore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WilksScoreController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $scores = $request->user()->wilksScores()
            ->orderByDesc('created_at')
            ->paginate(20);

        return WilksScoreResource::collection($scores);
    }

    public function store(StoreWilksScoreRequest $request, CreateWilksScoreAction $action): WilksScoreResource
    {
        /** @var array{body_weight: float, lifted_weight: float, gender: 'male'|'female', unit: 'kg'|'lbs'} $validated */
        $validated = $request->validated();

        $wilksScore = $action->execute($request->user(), $validated);

        return new WilksScoreResource($wilksScore);
    }

    public function show(WilksScore $wilksScore): WilksScoreResource
    {
        if ($wilksScore->user_id !== auth()->id()) {
            abort(403);
        }

        return new WilksScoreResource($wilksScore);
    }

    public function update(StoreWilksScoreRequest $request, WilksScore $wilksScore, UpdateWilksScoreAction $action): WilksScoreResource
    {
        if ($wilksScore->user_id !== $request->user()->id) {
            abort(403);
        }

        /** @var array{body_weight: float, lifted_weight: float, gender: 'male'|'female', unit: 'kg'|'lbs'} $validated */
        $validated = $request->validated();

        $updatedScore = $action->execute($wilksScore, $validated);

        return new WilksScoreResource($updatedScore);
    }

    public function destroy(WilksScore $wilksScore): \Illuminate\Http\Response
    {
        if ($wilksScore->user_id !== auth()->id()) {
            abort(403);
        }

        $wilksScore->delete();

        return response()->noContent();
    }
}
