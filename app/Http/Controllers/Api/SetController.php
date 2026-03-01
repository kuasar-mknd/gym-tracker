<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Workouts\CreateSetAction;
use App\Http\Requests\Api\SetStoreRequest;
use App\Http\Requests\Api\SetUpdateRequest;
use App\Http\Resources\SetResource;
use App\Models\Set;
use App\Models\WorkoutLine;
use App\Services\StatsService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\QueryBuilder;

class SetController extends Controller
{
    use AuthorizesRequests;

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
    public function store(SetStoreRequest $request, CreateSetAction $createSetAction): SetResource
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        try {
            /** @var \App\Models\WorkoutLine $workoutLine */
            $workoutLine = WorkoutLine::findOrFail($validated['workout_line_id']);

            $this->authorize('create', [Set::class, $workoutLine]);

            $data = collect($validated)->except('workout_line_id')->toArray();
            /** @var \App\Models\User $user */
            $user = $this->user();

            $set = $createSetAction->execute($user, $workoutLine, $data);

            return new SetResource($set);
        } catch (\Exception $e) {
            \Log::error('Failed to create set in API:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'data' => $validated,
            ]);

            throw $e;
        }
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
    public function update(SetUpdateRequest $request, Set $set, StatsService $statsService): SetResource
    {
        $this->authorize('update', $set);

        $set->update($request->validated());

        $statsService->clearWorkoutRelatedStats($this->user());

        return new SetResource($set);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Set $set, StatsService $statsService): \Illuminate\Http\Response
    {
        $this->authorize('delete', $set);

        $user = $this->user();
        $set->delete();

        $statsService->clearWorkoutRelatedStats($user);

        return response()->noContent();
    }
}
