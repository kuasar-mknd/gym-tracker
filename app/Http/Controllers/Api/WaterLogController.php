<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreWaterLogRequest;
use App\Http\Requests\UpdateWaterLogRequest;
use App\Http\Resources\WaterLogResource;
use App\Models\WaterLog;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class WaterLogController extends Controller
{
    #[OA\Get(
        path: '/water-logs',
        summary: 'Get list of water logs',
        tags: ['Water Logs']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function index(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', WaterLog::class);

        $logs = QueryBuilder::for(WaterLog::class)
            ->allowedFilters([
                AllowedFilter::exact('amount'),
                AllowedFilter::scope('consumed_at_between', 'consumedAtBetween'),
            ])
            ->allowedSorts(['consumed_at', 'amount', 'created_at'])
            ->defaultSort('-consumed_at')
            ->where('user_id', $this->user()->id)
            ->paginate();

        return WaterLogResource::collection($logs);
    }

    #[OA\Post(
        path: '/water-logs',
        summary: 'Create a new water log',
        tags: ['Water Logs']
    )]
    #[OA\Response(response: 201, description: 'Created successfully')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function store(StoreWaterLogRequest $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('create', WaterLog::class);

        $validated = $request->validated();

        $log = new WaterLog($validated);
        /** @var int<0, max> $userId */
        $userId = abs((int) $this->user()->id);
        $log->user_id = $userId;
        $log->save();

        return (new WaterLogResource($log))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/water-logs/{water_log}',
        summary: 'Get a specific water log',
        tags: ['Water Logs']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 404, description: 'Not found')]
    public function show(WaterLog $waterLog): WaterLogResource
    {
        $this->authorize('view', $waterLog);

        return new WaterLogResource($waterLog);
    }

    #[OA\Put(
        path: '/water-logs/{water_log}',
        summary: 'Update a water log',
        tags: ['Water Logs']
    )]
    #[OA\Response(response: 200, description: 'Updated successfully')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function update(UpdateWaterLogRequest $request, WaterLog $waterLog): WaterLogResource
    {
        $this->authorize('update', $waterLog);

        $validated = $request->validated();

        $waterLog->update($validated);

        return new WaterLogResource($waterLog);
    }

    #[OA\Delete(
        path: '/water-logs/{water_log}',
        summary: 'Delete a water log',
        tags: ['Water Logs']
    )]
    #[OA\Response(response: 204, description: 'Deleted successfully')]
    public function destroy(WaterLog $waterLog): \Illuminate\Http\Response
    {
        $this->authorize('delete', $waterLog);

        $waterLog->delete();

        return response()->noContent();
    }
}
