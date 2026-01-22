<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\SupplementLogStoreRequest;
use App\Http\Requests\Api\SupplementLogUpdateRequest;
use App\Http\Resources\SupplementLogResource;
use App\Models\SupplementLog;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;
use Spatie\QueryBuilder\QueryBuilder;

class SupplementLogController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/supplement-logs',
        summary: 'Get list of supplement logs',
        tags: ['SupplementLogs']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function index(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', SupplementLog::class);

        $logs = QueryBuilder::for(SupplementLog::class)
            ->allowedIncludes(['supplement'])
            ->allowedFilters(['supplement_id'])
            ->allowedSorts(['consumed_at', 'created_at'])
            ->defaultSort('-consumed_at')
            ->where('user_id', $this->user()->id)
            ->paginate();

        return SupplementLogResource::collection($logs);
    }

    #[OA\Post(
        path: '/supplement-logs',
        summary: 'Create a new supplement log',
        tags: ['SupplementLogs']
    )]
    #[OA\Response(response: 201, description: 'Created successfully')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function store(SupplementLogStoreRequest $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('create', SupplementLog::class);

        $validated = $request->validated();

        $log = new SupplementLog($validated);
        /** @var int<0, max> $userId */
        $userId = $this->user()->id;
        $log->user_id = $userId;
        $log->save();

        return (new SupplementLogResource($log))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/supplement-logs/{supplementLog}',
        summary: 'Get a specific supplement log',
        tags: ['SupplementLogs']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 404, description: 'Not found')]
    public function show(SupplementLog $supplementLog): SupplementLogResource
    {
        $this->authorize('view', $supplementLog);

        $supplementLog->load(['supplement']);

        return new SupplementLogResource($supplementLog);
    }

    #[OA\Put(
        path: '/supplement-logs/{supplementLog}',
        summary: 'Update a supplement log',
        tags: ['SupplementLogs']
    )]
    #[OA\Response(response: 200, description: 'Updated successfully')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function update(SupplementLogUpdateRequest $request, SupplementLog $supplementLog): SupplementLogResource
    {
        $this->authorize('update', $supplementLog);

        $validated = $request->validated();

        $supplementLog->update($validated);

        return new SupplementLogResource($supplementLog);
    }

    #[OA\Delete(
        path: '/supplement-logs/{supplementLog}',
        summary: 'Delete a supplement log',
        tags: ['SupplementLogs']
    )]
    #[OA\Response(response: 204, description: 'Deleted successfully')]
    public function destroy(SupplementLog $supplementLog): \Illuminate\Http\Response
    {
        $this->authorize('delete', $supplementLog);

        $supplementLog->delete();

        return response()->noContent();
    }
}
