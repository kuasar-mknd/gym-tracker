<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\BodyPartMeasurementStoreRequest;
use App\Http\Requests\BodyPartMeasurementUpdateRequest;
use App\Http\Resources\BodyPartMeasurementResource;
use App\Models\BodyPartMeasurement;
use OpenApi\Attributes as OA;
use Spatie\QueryBuilder\QueryBuilder;

class BodyPartMeasurementController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    #[OA\Get(
        path: '/body-part-measurements',
        summary: 'Get list of body part measurements',
        tags: ['BodyPartMeasurements']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function index(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $measurements = QueryBuilder::for(BodyPartMeasurement::class)
            ->allowedSorts(['measured_at', 'value', 'part', 'created_at'])
            ->allowedFilters(['part'])
            ->defaultSort('-measured_at')
            ->where('user_id', $this->user()->id)
            ->paginate();

        return BodyPartMeasurementResource::collection($measurements);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(BodyPartMeasurementStoreRequest $request): BodyPartMeasurementResource
    {
        $measurement = new BodyPartMeasurement($request->validated());
        $measurement->user_id = $this->user()->id;
        $measurement->save();

        return new BodyPartMeasurementResource($measurement);
    }

    /**
     * Display the specified resource.
     */
    public function show(BodyPartMeasurement $bodyPartMeasurement): BodyPartMeasurementResource
    {
        if ($bodyPartMeasurement->user_id !== $this->user()->id) {
            abort(403);
        }

        return new BodyPartMeasurementResource($bodyPartMeasurement);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(BodyPartMeasurementUpdateRequest $request, BodyPartMeasurement $bodyPartMeasurement): BodyPartMeasurementResource
    {
        if ($bodyPartMeasurement->user_id !== $this->user()->id) {
            abort(403);
        }

        $bodyPartMeasurement->update($request->validated());

        return new BodyPartMeasurementResource($bodyPartMeasurement);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BodyPartMeasurement $bodyPartMeasurement): \Illuminate\Http\Response
    {
        if ($bodyPartMeasurement->user_id !== $this->user()->id) {
            abort(403);
        }

        $bodyPartMeasurement->delete();

        return response()->noContent();
    }
}
