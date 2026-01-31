<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\WarmupPreferenceStoreRequest;
use App\Http\Requests\Api\WarmupPreferenceUpdateRequest;
use App\Http\Resources\WarmupPreferenceResource;
use App\Models\WarmupPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\QueryBuilder;

class WarmupPreferenceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WarmupPreference::class);

        /** @var \Spatie\QueryBuilder\QueryBuilder $query */
        $query = QueryBuilder::for(WarmupPreference::class);
        $preferences = $query
            ->where('user_id', $this->user()->id)
            ->allowedSorts(['created_at', 'updated_at'])
            ->get();

        return WarmupPreferenceResource::collection($preferences);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(WarmupPreferenceStoreRequest $request): JsonResponse
    {
        $this->authorize('create', WarmupPreference::class);

        $validated = $request->validated();
        $validated['user_id'] = $this->user()->id;

        $preference = WarmupPreference::create($validated);

        return (new WarmupPreferenceResource($preference))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(WarmupPreference $warmup_preference): WarmupPreferenceResource
    {
        $this->authorize('view', $warmup_preference);

        return new WarmupPreferenceResource($warmup_preference);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(WarmupPreferenceUpdateRequest $request, WarmupPreference $warmup_preference): WarmupPreferenceResource
    {
        $this->authorize('update', $warmup_preference);

        $warmup_preference->update($request->validated());

        return new WarmupPreferenceResource($warmup_preference);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(WarmupPreference $warmup_preference): Response
    {
        $this->authorize('delete', $warmup_preference);

        $warmup_preference->delete();

        return response()->noContent();
    }
}
