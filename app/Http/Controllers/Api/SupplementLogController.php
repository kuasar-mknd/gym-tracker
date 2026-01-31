<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreSupplementLogRequest;
use App\Http\Requests\Api\UpdateSupplementLogRequest;
use App\Http\Resources\SupplementLogResource;
use App\Models\Supplement;
use App\Models\SupplementLog;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class SupplementLogController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $logs = QueryBuilder::for(SupplementLog::class)
            ->allowedFilters([
                AllowedFilter::exact('supplement_id'),
            ])
            ->allowedSorts(['consumed_at', 'created_at'])
            ->defaultSort('-consumed_at')
            ->where('user_id', $this->user()->id)
            ->with(['supplement'])
            ->paginate();

        return SupplementLogResource::collection($logs);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSupplementLogRequest $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validated();

        $log = new SupplementLog($validated);
        $log->user_id = $this->user()->id;

        if (! isset($validated['consumed_at'])) {
            $log->consumed_at = now();
        }

        $log->save();

        return (new SupplementLogResource($log))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(SupplementLog $supplementLog): SupplementLogResource
    {
        if ($supplementLog->user_id !== $this->user()->id) {
            abort(403);
        }

        $supplementLog->load('supplement');

        return new SupplementLogResource($supplementLog);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSupplementLogRequest $request, SupplementLog $supplementLog): SupplementLogResource
    {
        if ($supplementLog->user_id !== $this->user()->id) {
            abort(403);
        }

        $supplementLog->update($request->validated());

        return new SupplementLogResource($supplementLog);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SupplementLog $supplementLog): Response
    {
        if ($supplementLog->user_id !== $this->user()->id) {
            abort(403);
        }

        $supplementLog->delete();

        return response()->noContent();
    }
}
