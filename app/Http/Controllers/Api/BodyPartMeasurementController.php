<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\BodyPartMeasurementStoreRequest;
use App\Http\Requests\BodyPartMeasurementUpdateRequest;
use App\Http\Resources\BodyPartMeasurementResource;
use App\Models\BodyPartMeasurement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BodyPartMeasurementController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $measurements = $user->bodyPartMeasurements()
            ->orderByDesc('measured_at')
            ->paginate();

        return BodyPartMeasurementResource::collection($measurements);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(BodyPartMeasurementStoreRequest $request): BodyPartMeasurementResource
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $measurement = $user->bodyPartMeasurements()->create($request->validated());

        return new BodyPartMeasurementResource($measurement);
    }

    /**
     * Display the specified resource.
     */
    public function show(BodyPartMeasurement $bodyPartMeasurement): BodyPartMeasurementResource
    {
        $this->authorize('view', $bodyPartMeasurement);

        return new BodyPartMeasurementResource($bodyPartMeasurement);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(BodyPartMeasurementUpdateRequest $request, BodyPartMeasurement $bodyPartMeasurement): BodyPartMeasurementResource
    {
        $this->authorize('update', $bodyPartMeasurement);

        $bodyPartMeasurement->update($request->validated());

        return new BodyPartMeasurementResource($bodyPartMeasurement);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BodyPartMeasurement $bodyPartMeasurement): Response
    {
        $this->authorize('delete', $bodyPartMeasurement);

        $bodyPartMeasurement->delete();

        return response()->noContent();
    }
}
