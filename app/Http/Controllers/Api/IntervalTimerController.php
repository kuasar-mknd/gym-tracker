<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreIntervalTimerRequest;
use App\Http\Requests\UpdateIntervalTimerRequest;
use App\Http\Resources\IntervalTimerResource;
use App\Models\IntervalTimer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
     * @param  StoreIntervalTimerRequest  $request  The validated request containing timer details.
     * @return IntervalTimerResource The created interval timer resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function store(StoreIntervalTimerRequest $request): IntervalTimerResource
    {
        $this->authorize('create', IntervalTimer::class);

        $intervalTimer = $this->user()->intervalTimers()->create($request->validated());

        return new IntervalTimerResource($intervalTimer);
    }

    /**
     * Display the specified interval timer.
     *
     * @param  IntervalTimer  $intervalTimer  The interval timer to display.
     * @return IntervalTimerResource The interval timer resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user does not own the timer.
     */
    public function show(IntervalTimer $intervalTimer): IntervalTimerResource
    {
        $this->authorize('view', $intervalTimer);

        return new IntervalTimerResource($intervalTimer);
    }

    /**
     * Update the specified interval timer in storage.
     *
     * @param  UpdateIntervalTimerRequest  $request  The validated request containing updated timer details.
     * @param  IntervalTimer  $intervalTimer  The interval timer to update.
     * @return IntervalTimerResource The updated interval timer resource.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user does not own the timer.
     */
    public function update(UpdateIntervalTimerRequest $request, IntervalTimer $intervalTimer): IntervalTimerResource
    {
        $this->authorize('update', $intervalTimer);

        $intervalTimer->update($request->validated());

        return new IntervalTimerResource($intervalTimer);
    }

    /**
     * Remove the specified interval timer from storage.
     *
     * @param  IntervalTimer  $intervalTimer  The interval timer to delete.
     * @return JsonResponse A 204 No Content response.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user does not own the timer.
     */
    public function destroy(IntervalTimer $intervalTimer): JsonResponse
    {
        $this->authorize('delete', $intervalTimer);

        $intervalTimer->delete();

        return response()->json(null, 204);
    }
}
