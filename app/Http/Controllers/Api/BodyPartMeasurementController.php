<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\BodyPartMeasurementStoreRequest;
use App\Http\Requests\BodyPartMeasurementUpdateRequest;
use App\Http\Resources\BodyPartMeasurementResource;
use App\Models\BodyPartMeasurement;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;
use Spatie\QueryBuilder\QueryBuilder;

class BodyPartMeasurementController extends Controller
{
    use AuthorizesRequests;

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
        $this->authorize('viewAny', BodyPartMeasurement::class);

        $measurements = QueryBuilder::for(BodyPartMeasurement::class)
            ->allowedSorts(['measured_at', 'value', 'created_at', 'part'])
            ->defaultSort('-measured_at')
            ->where('user_id', $this->user()->id)
            ->paginate();

        return BodyPartMeasurementResource::collection($measurements);
    }

    /**
     * Store a newly created resource in storage.
     */
    #[OA\Post(
        path: '/body-part-measurements',
        summary: 'Create a new body part measurement',
        tags: ['BodyPartMeasurements']
    )]
    #[OA\Response(response: 201, description: 'Created successfully')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function store(BodyPartMeasurementStoreRequest $request): BodyPartMeasurementResource
    {
        $validated = $request->validated();

        $measurement = new BodyPartMeasurement($validated);
        $measurement->user_id = $this->user()->id;
        $measurement->save();

        return new BodyPartMeasurementResource($measurement);
    }

    /**
     * Display the specified resource.
     */
    #[OA\Get(
        path: '/body-part-measurements/{bodyPartMeasurement}',
        summary: 'Get a specific body part measurement',
        tags: ['BodyPartMeasurements']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 404, description: 'Not Found')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function show(BodyPartMeasurement $bodyPartMeasurement): BodyPartMeasurementResource
    {
        $this->authorize('view', $bodyPartMeasurement);

        return new BodyPartMeasurementResource($bodyPartMeasurement);
    }

    /**
     * Update the specified resource in storage.
     */
    #[OA\Put(
        path: '/body-part-measurements/{bodyPartMeasurement}',
        summary: 'Update a body part measurement',
        tags: ['BodyPartMeasurements']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 404, description: 'Not Found')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function update(BodyPartMeasurementUpdateRequest $request, BodyPartMeasurement $bodyPartMeasurement): BodyPartMeasurementResource
    {
        $this->authorize('update', $bodyPartMeasurement);

        $validated = $request->validated();
        $bodyPartMeasurement->update($validated);

        return new BodyPartMeasurementResource($bodyPartMeasurement);
    }

    /**
     * Remove the specified resource from storage.
     */
    #[OA\Delete(
        path: '/body-part-measurements/{bodyPartMeasurement}',
        summary: 'Delete a body part measurement',
        tags: ['BodyPartMeasurements']
    )]
    #[OA\Response(response: 204, description: 'Deleted successfully')]
    #[OA\Response(response: 404, description: 'Not Found')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function destroy(BodyPartMeasurement $bodyPartMeasurement): Response
    {
        $this->authorize('delete', $bodyPartMeasurement);

        $bodyPartMeasurement->delete();

        return response()->noContent();
    }
}
