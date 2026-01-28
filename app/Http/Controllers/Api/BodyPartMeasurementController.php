<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\BodyPartMeasurementStoreRequest;
use App\Http\Requests\BodyPartMeasurementUpdateRequest;
use App\Http\Resources\BodyPartMeasurementResource;
use App\Models\BodyPartMeasurement;
use Spatie\QueryBuilder\QueryBuilder;

class BodyPartMeasurementController extends Controller
{
    public function index(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $measurements = QueryBuilder::for(BodyPartMeasurement::class)
            ->allowedFilters(['part', 'measured_at'])
            ->allowedSorts(['measured_at', 'created_at'])
            ->defaultSort('-measured_at')
            ->where('user_id', $this->user()->id)
            ->paginate();

        return BodyPartMeasurementResource::collection($measurements);
    }

    public function store(BodyPartMeasurementStoreRequest $request): BodyPartMeasurementResource
    {
        /** @var BodyPartMeasurement $measurement */
        $measurement = $this->user()->bodyPartMeasurements()->create($request->validated());

        return new BodyPartMeasurementResource($measurement);
    }

    public function show(BodyPartMeasurement $bodyPartMeasurement): BodyPartMeasurementResource
    {
        $this->authorize('view', $bodyPartMeasurement);

        return new BodyPartMeasurementResource($bodyPartMeasurement);
    }

    public function update(BodyPartMeasurementUpdateRequest $request, BodyPartMeasurement $bodyPartMeasurement): BodyPartMeasurementResource
    {
        $this->authorize('update', $bodyPartMeasurement);

        $bodyPartMeasurement->update($request->validated());

        return new BodyPartMeasurementResource($bodyPartMeasurement);
    }

    public function destroy(BodyPartMeasurement $bodyPartMeasurement): \Illuminate\Http\Response
    {
        $this->authorize('delete', $bodyPartMeasurement);

        $bodyPartMeasurement->delete();

        return response()->noContent();
    }
}
