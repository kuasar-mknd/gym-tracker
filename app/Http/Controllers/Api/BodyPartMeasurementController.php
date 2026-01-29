<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\BodyPartMeasurementStoreRequest;
use App\Http\Requests\BodyPartMeasurementUpdateRequest;
use App\Http\Resources\BodyPartMeasurementResource;
use App\Models\BodyPartMeasurement;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Spatie\QueryBuilder\QueryBuilder;

class BodyPartMeasurementController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BodyPartMeasurement::class);

        $measurements = QueryBuilder::for(BodyPartMeasurement::class)
            ->allowedFilters(['part'])
            ->allowedSorts(['measured_at', 'created_at', 'value'])
            ->defaultSort('-measured_at')
            ->where('user_id', Auth::id())
            ->paginate();

        return BodyPartMeasurementResource::collection($measurements);
    }

    public function store(BodyPartMeasurementStoreRequest $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('create', BodyPartMeasurement::class);

        $data = $request->validated();

        /** @var \App\Models\User $user */
        $user = $this->user();

        $measurement = $user->bodyPartMeasurements()->create($data);

        return (new BodyPartMeasurementResource($measurement))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
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

    public function destroy(BodyPartMeasurement $bodyPartMeasurement): Response
    {
        $this->authorize('delete', $bodyPartMeasurement);

        $bodyPartMeasurement->delete();

        return response()->noContent();
    }
}
