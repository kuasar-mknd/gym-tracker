<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\BodyPartMeasurementStoreRequest;
use App\Http\Requests\BodyPartMeasurementUpdateRequest;
use App\Http\Resources\BodyPartMeasurementResource;
use App\Models\BodyPartMeasurement;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
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
    public function index(): AnonymousResourceCollection
    {
        $measurements = QueryBuilder::for(BodyPartMeasurement::class)
            ->allowedSorts(['measured_at', 'created_at'])
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
    public function show(BodyPartMeasurement $body_part_measurement): BodyPartMeasurementResource
    {
        if ($body_part_measurement->user_id !== $this->user()->id) {
            abort(403);
        }

        return new BodyPartMeasurementResource($body_part_measurement);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(BodyPartMeasurementUpdateRequest $request, BodyPartMeasurement $body_part_measurement): BodyPartMeasurementResource
    {
        if ($body_part_measurement->user_id !== $this->user()->id) {
            abort(403);
        }

        $body_part_measurement->update($request->validated());

        return new BodyPartMeasurementResource($body_part_measurement);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BodyPartMeasurement $body_part_measurement): Response
    {
        if ($body_part_measurement->user_id !== $this->user()->id) {
            abort(403);
        }

        $body_part_measurement->delete();

        return response()->noContent();
    }
}
