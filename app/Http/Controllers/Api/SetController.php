<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\SetStoreRequest;
use App\Http\Requests\Api\SetUpdateRequest;
use App\Http\Resources\SetResource;
use App\Models\Set;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\QueryBuilder\QueryBuilder;

class SetController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Set::class);

        $sets = QueryBuilder::for(Set::class)
            ->allowedFilters(['workout_line_id'])
            ->whereHas('workoutLine.workout', function ($query) {
                $query->where('user_id', Auth::id());
            })
            ->paginate();

        return SetResource::collection($sets);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(SetStoreRequest $request)
    {
        $validated = $request->validated();

        $workoutLine = WorkoutLine::findOrFail($validated['workout_line_id']);

        $set = $workoutLine->sets()->create($validated);

        return new SetResource($set);
    }

    /**
     * Display the specified resource.
     */
    public function show(Set $set)
    {
        $this->authorize('view', $set);

        return new SetResource($set);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(SetUpdateRequest $request, Set $set)
    {
        $this->authorize('update', $set);

        $set->update($request->validated());

        return new SetResource($set);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Set $set)
    {
        $this->authorize('delete', $set);

        $set->delete();

        return response()->noContent();
    }
}
