<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\BodyMeasurementResource;
use App\Models\BodyMeasurement;
use Illuminate\Http\Request;
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
    public function store(Request $request)
    {
        $validated = $request->validate([
            'weight' => 'required|numeric|min:0',
            'measured_at' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $measurement = new BodyMeasurement($validated);
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
    public function update(Request $request, BodyMeasurement $bodyMeasurement)
    {
        if ($bodyMeasurement->user_id !== Auth::id()) {
            abort(403);
        }

        $validated = $request->validate([
            'weight' => 'sometimes|required|numeric|min:0',
            'measured_at' => 'sometimes|required|date',
            'notes' => 'nullable|string',
        ]);

        $bodyMeasurement->update($validated);

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
