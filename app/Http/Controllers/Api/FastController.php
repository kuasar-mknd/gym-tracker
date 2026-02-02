<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreFastRequest;
use App\Http\Requests\Api\UpdateFastRequest;
use App\Http\Resources\FastResource;
use App\Models\Fast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FastController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $fasts = $this->user()->fasts()
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return FastResource::collection($fasts);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreFastRequest $request): FastResource
    {
        $validated = $request->validated();

        /** @var Fast $fast */
        $fast = $this->user()->fasts()->create(array_merge(
            $validated,
            ['status' => 'active']
        ));

        return new FastResource($fast);
    }

    /**
     * Display the specified resource.
     */
    public function show(Fast $fast): FastResource
    {
        if ($fast->user_id !== $this->user()->id) {
            abort(403);
        }

        return new FastResource($fast);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateFastRequest $request, Fast $fast): FastResource
    {
        $fast->update($request->validated());

        return new FastResource($fast);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Fast $fast): JsonResponse
    {
        if ($fast->user_id !== $this->user()->id) {
            abort(403);
        }

        $fast->delete();

        return response()->json(null, 204);
    }
}
