<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Workouts\StoreSetAction;
use App\Http\Requests\Api\SetStoreRequest;
use App\Http\Requests\Api\SetUpdateRequest;
use App\Http\Resources\SetResource;
use App\Models\Set;
use App\Services\StatsService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\QueryBuilder;

class SetController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected StatsService $statsService
    ) {
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', Set::class);

        $sets = QueryBuilder::for(Set::class)
            ->allowedFilters(['workout_line_id'])
            ->whereHas('workoutLine.workout', function ($query): void {
                $query->where('user_id', $this->user()->id);
            })
            ->paginate();

        return SetResource::collection($sets);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(SetStoreRequest $request, StoreSetAction $action): SetResource
    {
        $set = $action->execute($this->user(), $request->validated());

        return new SetResource($set);
    }

    /**
     * Display the specified resource.
     */
    public function show(Set $set): SetResource
    {
        $this->authorize('view', $set);

        return new SetResource($set);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(SetUpdateRequest $request, Set $set): SetResource
    {
        $this->authorize('update', $set);

        $set->update($request->validated());

        // Bolt: Only clear volume-related stats for set updates
        $this->statsService->clearVolumeStats($this->user());

        return new SetResource($set);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Set $set): \Illuminate\Http\Response
    {
        $this->authorize('delete', $set);

        $user = $this->user();
        $set->delete();

        // Bolt: Only clear volume-related stats for set deletions
        $this->statsService->clearVolumeStats($user);

        return response()->noContent();
    }
}
