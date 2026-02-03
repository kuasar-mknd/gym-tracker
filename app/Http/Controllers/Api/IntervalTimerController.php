<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreIntervalTimerRequest;
use App\Http\Requests\UpdateIntervalTimerRequest;
use App\Http\Resources\IntervalTimerResource;
use App\Models\IntervalTimer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class IntervalTimerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        return IntervalTimerResource::collection(
            $this->user()->intervalTimers()->latest()->get()
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreIntervalTimerRequest $request): IntervalTimerResource
    {
        $intervalTimer = $this->user()->intervalTimers()->create($request->validated());

        return new IntervalTimerResource($intervalTimer);
    }

    /**
     * Display the specified resource.
     */
    public function show(IntervalTimer $intervalTimer): IntervalTimerResource
    {
        if ($intervalTimer->user_id != $this->user()->id) {
            abort(403);
        }

        return new IntervalTimerResource($intervalTimer);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateIntervalTimerRequest $request, IntervalTimer $intervalTimer): IntervalTimerResource
    {
        if ($intervalTimer->user_id != $this->user()->id) {
            abort(403);
        }

        $intervalTimer->update($request->validated());

        return new IntervalTimerResource($intervalTimer);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(IntervalTimer $intervalTimer): JsonResponse
    {
        if ($intervalTimer->user_id != $this->user()->id) {
            abort(403);
        }

        $intervalTimer->delete();

        return response()->json(null, 204);
    }
}
