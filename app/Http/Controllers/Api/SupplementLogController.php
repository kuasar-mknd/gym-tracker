<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplementLogRequest;
use App\Http\Requests\UpdateSupplementLogRequest;
use App\Http\Resources\SupplementLogResource;
use App\Models\SupplementLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\QueryBuilder;

class SupplementLogController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SupplementLog::class);

        /** @var int $perPage */
        $perPage = $request->input('per_page', 15);

        $logs = QueryBuilder::for(SupplementLog::class)
            ->allowedFilters(['supplement_id'])
            ->allowedSorts(['consumed_at', 'created_at'])
            ->allowedIncludes(['supplement'])
            ->where('user_id', $this->user()->id)
            ->paginate($perPage);

        return SupplementLogResource::collection($logs);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSupplementLogRequest $request): SupplementLogResource
    {
        $this->authorize('create', SupplementLog::class);

        $log = $this->user()->supplementLogs()->create($request->validated());

        return new SupplementLogResource($log);
    }

    /**
     * Display the specified resource.
     */
    public function show(SupplementLog $supplementLog): SupplementLogResource
    {
        $this->authorize('view', $supplementLog);

        return new SupplementLogResource($supplementLog);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSupplementLogRequest $request, SupplementLog $supplementLog): SupplementLogResource
    {
        $this->authorize('update', $supplementLog);

        $supplementLog->update($request->validated());

        return new SupplementLogResource($supplementLog);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SupplementLog $supplementLog): JsonResponse
    {
        $this->authorize('delete', $supplementLog);

        $supplementLog->delete();

        return response()->json(null, 204);
    }
}
