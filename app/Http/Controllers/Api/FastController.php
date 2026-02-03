<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFastRequest;
use App\Http\Requests\UpdateFastRequest;
use App\Http\Resources\FastResource;
use App\Models\Fast;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class FastController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Fast::class);

        $fasts = $this->user()->fasts()
            ->latest()
            ->paginate();

        return FastResource::collection($fasts);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreFastRequest $request): FastResource
    {
        $this->authorize('create', Fast::class);

        $validated = $request->validated();

        /** @var Fast $fast */
        $fast = $this->user()->fasts()->create($validated);

        $fast->refresh();

        return new FastResource($fast);
    }

    /**
     * Display the specified resource.
     */
    public function show(Fast $fast): FastResource
    {
        $this->authorize('view', $fast);

        return new FastResource($fast);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateFastRequest $request, Fast $fast): FastResource
    {
        $this->authorize('update', $fast);

        $fast->update($request->validated());

        return new FastResource($fast);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Fast $fast): Response
    {
        $this->authorize('delete', $fast);

        $fast->delete();

        return response()->noContent();
    }
}
