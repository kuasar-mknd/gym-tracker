<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\SupplementLogs\CreateSupplementLogAction;
use App\Actions\SupplementLogs\DeleteSupplementLogAction;
use App\Actions\SupplementLogs\UpdateSupplementLogAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SupplementLogStoreRequest;
use App\Http\Requests\Api\SupplementLogUpdateRequest;
use App\Http\Resources\SupplementLogResource;
use App\Models\SupplementLog;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;
use Spatie\QueryBuilder\AllowedFilter;
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
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SupplementLog::class);

        $logs = QueryBuilder::for(SupplementLog::class)
            ->allowedIncludes(['supplement'])
            ->allowedFilters([
                'supplement_id',
                AllowedFilter::callback('date_from', function ($query, $value) {
                    $query->where('consumed_at', '>=', $value);
                }),
                AllowedFilter::callback('date_to', function ($query, $value) {
                    $query->where('consumed_at', '<=', $value);
                }),
            ])
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
    public function store(
        SupplementLogStoreRequest $request,
        CreateSupplementLogAction $action
    ): JsonResponse {
        $this->authorize('create', SupplementLog::class);

        $log = $action->execute($this->user(), $request->validated());

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
    public function update(
        SupplementLogUpdateRequest $request,
        SupplementLog $supplementLog,
        UpdateSupplementLogAction $action
    ): SupplementLogResource {
        $this->authorize('update', $supplementLog);

        $log = $action->execute($supplementLog, $request->validated());

        return new SupplementLogResource($log);
    }

    #[OA\Delete(
        path: '/supplement-logs/{supplementLog}',
        summary: 'Delete a supplement log',
        tags: ['SupplementLogs']
    )]
    #[OA\Response(response: 204, description: 'Deleted successfully')]
    public function destroy(
        SupplementLog $supplementLog,
        DeleteSupplementLogAction $action
    ): Response {
        $this->authorize('delete', $supplementLog);

        $action->execute($supplementLog);

        return response()->noContent();
    }
}
