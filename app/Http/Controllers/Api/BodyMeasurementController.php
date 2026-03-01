<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\BodyMeasurementStoreRequest;
use App\Http\Requests\BodyMeasurementUpdateRequest;
use App\Http\Resources\BodyMeasurementResource;
use App\Models\BodyMeasurement;
use OpenApi\Attributes as OA;
use Spatie\QueryBuilder\QueryBuilder;

class BodyMeasurementController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    #[OA\Get(
        path: '/body-measurements',
        summary: 'Get list of body measurements',
        tags: ['BodyMeasurements']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function index(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', BodyMeasurement::class);

        $measurements = QueryBuilder::for(BodyMeasurement::class)
            ->allowedSorts(['measured_at', 'weight', 'created_at'])
            ->defaultSort('-measured_at')
            ->where('user_id', $this->user()->id)
            ->paginate();

        return BodyMeasurementResource::collection($measurements);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(BodyMeasurementStoreRequest $request): BodyMeasurementResource
    {
        $this->authorize('create', BodyMeasurement::class);

        $measurement = new BodyMeasurement($request->validated());
        $measurement->user_id = $this->user()->id;
        $measurement->save();

        return new BodyMeasurementResource($measurement);
    }

    /**
     * Display the specified resource.
     */
    public function show(BodyMeasurement $bodyMeasurement): BodyMeasurementResource
    {
        $this->authorize('view', $bodyMeasurement);

        return new BodyMeasurementResource($bodyMeasurement);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(BodyMeasurementUpdateRequest $request, BodyMeasurement $bodyMeasurement): BodyMeasurementResource
    {
        $this->authorize('update', $bodyMeasurement);

        $bodyMeasurement->update($request->validated());

        return new BodyMeasurementResource($bodyMeasurement);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BodyMeasurement $bodyMeasurement): \Illuminate\Http\Response
    {
        $this->authorize('delete', $bodyMeasurement);

        $bodyMeasurement->delete();

        return response()->noContent();
    }
}
