<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BodyPartMeasurementStoreRequest;
use App\Http\Requests\BodyPartMeasurementUpdateRequest;
use App\Http\Resources\BodyPartMeasurementResource;
use App\Models\BodyPartMeasurement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'BodyPartMeasurements', description: 'API Endpoints for Body Part Measurements')]
class BodyPartMeasurementController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    #[OA\Get(
        path: '/v1/body-part-measurements',
        summary: 'List body part measurements',
        tags: ['BodyPartMeasurements']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    public function index(Request $request): AnonymousResourceCollection
    {
        // Implicitly authorized as we only fetch auth user's data
        $measurements = $this->user()
            ->bodyPartMeasurements()
            ->orderBy('measured_at', 'desc')
            ->paginate(20);

        return BodyPartMeasurementResource::collection($measurements);
    }

    /**
     * Store a newly created resource in storage.
     */
    #[OA\Post(
        path: '/v1/body-part-measurements',
        summary: 'Create a new measurement',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/BodyPartMeasurementStoreRequest')
        ),
        tags: ['BodyPartMeasurements']
    )]
    #[OA\Response(response: 201, description: 'Created')]
    public function store(BodyPartMeasurementStoreRequest $request): BodyPartMeasurementResource
    {
        // Policy 'create' returns true, validation handles logic
        $measurement = $this->user()->bodyPartMeasurements()->create($request->validated());

        return new BodyPartMeasurementResource($measurement);
    }

    /**
     * Display the specified resource.
     */
    #[OA\Get(
        path: '/v1/body-part-measurements/{body_part_measurement}',
        summary: 'Get a specific measurement',
        tags: ['BodyPartMeasurements']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    public function show(BodyPartMeasurement $bodyPartMeasurement): BodyPartMeasurementResource
    {
        $this->authorize('view', $bodyPartMeasurement);

        return new BodyPartMeasurementResource($bodyPartMeasurement);
    }

    /**
     * Update the specified resource in storage.
     */
    #[OA\Put(
        path: '/v1/body-part-measurements/{body_part_measurement}',
        summary: 'Update a measurement',
        tags: ['BodyPartMeasurements']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    public function update(BodyPartMeasurementUpdateRequest $request, BodyPartMeasurement $bodyPartMeasurement): BodyPartMeasurementResource
    {
        $this->authorize('update', $bodyPartMeasurement);

        $bodyPartMeasurement->update($request->validated());

        return new BodyPartMeasurementResource($bodyPartMeasurement);
    }

    /**
     * Remove the specified resource from storage.
     */
    #[OA\Delete(
        path: '/v1/body-part-measurements/{body_part_measurement}',
        summary: 'Delete a measurement',
        tags: ['BodyPartMeasurements']
    )]
    #[OA\Response(response: 204, description: 'No Content')]
    public function destroy(BodyPartMeasurement $bodyPartMeasurement): Response
    {
        $this->authorize('delete', $bodyPartMeasurement);

        $bodyPartMeasurement->delete();

        return response()->noContent();
    }
}
