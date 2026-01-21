<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreHabitLogRequest;
use App\Http\Requests\UpdateHabitLogRequest;
use App\Http\Resources\HabitLogResource;
use App\Models\HabitLog;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class HabitLogController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/habit-logs',
        summary: 'Get list of habit logs',
        tags: ['HabitLogs']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function index(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', HabitLog::class);

        $logs = QueryBuilder::for(HabitLog::class)
            ->allowedIncludes(['habit'])
            ->allowedFilters([
                AllowedFilter::exact('habit_id'),
                AllowedFilter::scope('date_between', 'whereDateBetween'),
            ])
            ->allowedSorts(['date', 'created_at'])
            ->defaultSort('-date')
            ->whereHas('habit', function ($query): void {
                $query->where('user_id', $this->user()->id);
            })
            ->paginate();

        return HabitLogResource::collection($logs);
    }

    #[OA\Post(
        path: '/habit-logs',
        summary: 'Create a new habit log',
        tags: ['HabitLogs']
    )]
    #[OA\Response(response: 201, description: 'Created successfully')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function store(StoreHabitLogRequest $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('create', HabitLog::class);

        $validated = $request->validated();

        /** @var HabitLog $log */
        $log = HabitLog::create($validated);

        return (new HabitLogResource($log))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/habit-logs/{habit_log}',
        summary: 'Get a specific habit log',
        tags: ['HabitLogs']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 404, description: 'Not found')]
    public function show(HabitLog $habit_log): HabitLogResource
    {
        $this->authorize('view', $habit_log);

        $habit_log->load(['habit']);

        return new HabitLogResource($habit_log);
    }

    #[OA\Put(
        path: '/habit-logs/{habit_log}',
        summary: 'Update a habit log',
        tags: ['HabitLogs']
    )]
    #[OA\Response(response: 200, description: 'Updated successfully')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function update(UpdateHabitLogRequest $request, HabitLog $habit_log): HabitLogResource
    {
        // Authorization handled in UpdateHabitLogRequest

        $validated = $request->validated();

        $habit_log->update($validated);

        return new HabitLogResource($habit_log);
    }

    #[OA\Delete(
        path: '/habit-logs/{habit_log}',
        summary: 'Delete a habit log',
        tags: ['HabitLogs']
    )]
    #[OA\Response(response: 204, description: 'Deleted successfully')]
    public function destroy(HabitLog $habit_log): \Illuminate\Http\Response
    {
        $this->authorize('delete', $habit_log);

        $habit_log->delete();

        return response()->noContent();
    }
}
