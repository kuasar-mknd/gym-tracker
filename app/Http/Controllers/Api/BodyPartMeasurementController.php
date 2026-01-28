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
            ->allowedSorts(['measured_at', 'value', 'part', 'created_at'])
            ->defaultSort('-measured_at')
            ->where('user_id', $this->user()->id)
            ->paginate();

        return BodyPartMeasurementResource::collection($measurements);
    }

    public function store(BodyPartMeasurementStoreRequest $request): BodyPartMeasurementResource
    {
        $measurement = new BodyPartMeasurement($request->validated());
        $measurement->user_id = $this->user()->id;
        $measurement->save();

        return new BodyPartMeasurementResource($measurement);
    }

    public function show(BodyPartMeasurement $bodyPartMeasurement): BodyPartMeasurementResource
    {
        if ($bodyPartMeasurement->user_id !== $this->user()->id) {
            abort(403);
        }

        return new BodyPartMeasurementResource($bodyPartMeasurement);
    }

    public function update(BodyPartMeasurementUpdateRequest $request, BodyPartMeasurement $bodyPartMeasurement): BodyPartMeasurementResource
    {
        if ($bodyPartMeasurement->user_id !== $this->user()->id) {
            abort(403);
        }

        $bodyPartMeasurement->update($request->validated());

        return new BodyPartMeasurementResource($bodyPartMeasurement);
    }

    public function destroy(BodyPartMeasurement $bodyPartMeasurement): \Illuminate\Http\Response
    {
        if ($bodyPartMeasurement->user_id !== $this->user()->id) {
            abort(403);
        }

        $bodyPartMeasurement->delete();

        return response()->noContent();
    }
}
