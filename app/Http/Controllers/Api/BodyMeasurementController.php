<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\BodyMeasurementStoreRequest;
use App\Http\Requests\BodyMeasurementUpdateRequest;
use App\Http\Resources\BodyMeasurementResource;
use App\Models\BodyMeasurement;
use Illuminate\Support\Facades\Auth;
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
    public function index()
    {
        $measurements = QueryBuilder::for(BodyMeasurement::class)
            ->allowedSorts(['measured_at', 'weight', 'created_at'])
            ->defaultSort('-measured_at')
            ->where('user_id', Auth::id())
            ->paginate();

        return BodyMeasurementResource::collection($measurements);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(BodyMeasurementStoreRequest $request)
    {
        $measurement = new BodyMeasurement($request->validated());
        $measurement->user_id = Auth::id();
        $measurement->save();

        return new BodyMeasurementResource($measurement);
    }

    /**
     * Display the specified resource.
     */
    public function show(BodyMeasurement $bodyMeasurement)
    {
        if ($bodyMeasurement->user_id !== Auth::id()) {
            abort(403);
        }

        return new BodyMeasurementResource($bodyMeasurement);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(BodyMeasurementUpdateRequest $request, BodyMeasurement $bodyMeasurement)
    {
        if ($bodyMeasurement->user_id !== Auth::id()) {
            abort(403);
        }

        $bodyMeasurement->update($request->validated());

        return new BodyMeasurementResource($bodyMeasurement);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BodyMeasurement $bodyMeasurement)
    {
        if ($bodyMeasurement->user_id !== Auth::id()) {
            abort(403);
        }

        $bodyMeasurement->delete();

        return response()->noContent();
    }
}
