<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreIntervalTimerRequest;
use App\Http\Requests\UpdateIntervalTimerRequest;
use App\Http\Resources\IntervalTimerResource;
use App\Models\IntervalTimer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class IntervalTimerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $timers = $request->user()->intervalTimers()->latest()->get();

        return IntervalTimerResource::collection($timers);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreIntervalTimerRequest $request): IntervalTimerResource
    {
        $data = $request->validated();

        /** @var \App\Models\User $user */
        $user = $request->user();

        $timer = $user->intervalTimers()->create($data);

        return new IntervalTimerResource($timer);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, IntervalTimer $intervalTimer): IntervalTimerResource
    {
        if ($intervalTimer->user_id !== $request->user()->id) {
            abort(403);
        }

        return new IntervalTimerResource($intervalTimer);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateIntervalTimerRequest $request, IntervalTimer $intervalTimer): IntervalTimerResource
    {
        if ($intervalTimer->user_id !== $request->user()->id) {
            abort(403);
        }

        $intervalTimer->update($request->validated());

        return new IntervalTimerResource($intervalTimer);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, IntervalTimer $intervalTimer): Response
    {
        if ($intervalTimer->user_id !== $request->user()->id) {
            abort(403);
        }

        $intervalTimer->delete();

        return response()->noContent();
    }
}
