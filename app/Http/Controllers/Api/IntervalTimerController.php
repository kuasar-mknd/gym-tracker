<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreIntervalTimerRequest;
use App\Http\Requests\UpdateIntervalTimerRequest;
use App\Http\Resources\IntervalTimerResource;
use App\Models\IntervalTimer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Controller for managing user interval timers via API.
 *
 * This controller handles CRUD operations for the `IntervalTimer` model, ensuring
 * only authorized users can view, create, update, and delete their own timers.
 */
class IntervalTimerController extends Controller
{
    /**
     * Display a listing of the user's interval timers.
     *
     * Retrieves a list of interval timers belonging to the authenticated user,
     * ordered by the most recently created.
     *
     * @return AnonymousResourceCollection A collection of interval timer resources.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    #[OA\Get(
        path: '/interval-timers',
        summary: 'Get list of interval timers',
        tags: ['Interval Timers']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', IntervalTimer::class);

        return IntervalTimerResource::collection(
            $this->user()->intervalTimers()->latest()->get()
        );
    }

    /**
     * Store a newly created interval timer in storage.
     *
     * @param StoreIntervalTimerRequest $request The validated request containing timer details.
     * @return IntervalTimerResource The created interval timer resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    #[OA\Post(
        path: '/interval-timers',
        summary: 'Create a new interval timer',
        tags: ['Interval Timers']
    )]
    #[OA\Response(response: 201, description: 'Created successfully')]
    #[OA\Response(response: 422, description: 'Validation error')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function store(StoreIntervalTimerRequest $request): IntervalTimerResource
    {
        $this->authorize('create', IntervalTimer::class);

        $intervalTimer = $this->user()->intervalTimers()->create($request->validated());

        return new IntervalTimerResource($intervalTimer);
    }

    /**
     * Display the specified interval timer.
     *
     * @param IntervalTimer $intervalTimer The interval timer to display.
     * @return IntervalTimerResource The interval timer resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user does not own the timer.
     */
    #[OA\Get(
        path: '/interval-timers/{id}',
        summary: 'Get a specific interval timer',
        tags: ['Interval Timers']
    )]
    #[OA\Parameter(
        name: 'id',
        description: 'Timer ID',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Not found')]
    public function show(IntervalTimer $intervalTimer): IntervalTimerResource
    {
        $this->authorize('view', $intervalTimer);

        return new IntervalTimerResource($intervalTimer);
    }

    /**
     * Update the specified interval timer in storage.
     *
     * @param UpdateIntervalTimerRequest $request The validated request containing updated timer details.
     * @param IntervalTimer $intervalTimer The interval timer to update.
     * @return IntervalTimerResource The updated interval timer resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user does not own the timer.
     */
    #[OA\Put(
        path: '/interval-timers/{id}',
        summary: 'Update a specific interval timer',
        tags: ['Interval Timers']
    )]
    #[OA\Parameter(
        name: 'id',
        description: 'Timer ID',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 200, description: 'Updated successfully')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Not found')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function update(UpdateIntervalTimerRequest $request, IntervalTimer $intervalTimer): IntervalTimerResource
    {
        $this->authorize('update', $intervalTimer);

        $intervalTimer->update($request->validated());

        return new IntervalTimerResource($intervalTimer);
    }

    /**
     * Remove the specified interval timer from storage.
     *
     * @param IntervalTimer $intervalTimer The interval timer to delete.
     * @return JsonResponse A 204 No Content response.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user does not own the timer.
     */
    #[OA\Delete(
        path: '/interval-timers/{id}',
        summary: 'Delete a specific interval timer',
        tags: ['Interval Timers']
    )]
    #[OA\Parameter(
        name: 'id',
        description: 'Timer ID',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 204, description: 'Deleted successfully')]
    #[OA\Response(response: 403, description: 'Forbidden')]
    #[OA\Response(response: 404, description: 'Not found')]
    public function destroy(IntervalTimer $intervalTimer): JsonResponse
    {
        $this->authorize('delete', $intervalTimer);

        $intervalTimer->delete();

        return response()->json(null, 204);
    }
}
