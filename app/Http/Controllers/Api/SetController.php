<?php

namespace App\Http\Controllers\Api;

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
    public function store(Request $request)
    {
        $validated = $request->validate([
            'workout_line_id' => 'required|exists:workout_lines,id',
            'weight' => 'nullable|numeric|min:0',
            'reps' => 'nullable|integer|min:0',
            'duration_seconds' => 'nullable|integer|min:0',
            'distance_km' => 'nullable|numeric|min:0',
            'is_warmup' => 'boolean',
            'is_completed' => 'boolean',
        ]);

        // Verify ownership
        $workoutLine = WorkoutLine::findOrFail($validated['workout_line_id']);
        if ($workoutLine->workout->user_id !== Auth::id()) {
            abort(403, 'You do not own this workout line.');
        }

        $set = new Set;
        $set->fill($validated);
        $set->save();

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
    public function update(Request $request, Set $set)
    {
        $this->authorize('update', $set);

        $validated = $request->validate([
            'weight' => 'nullable|numeric|min:0',
            'reps' => 'nullable|integer|min:0',
            'duration_seconds' => 'nullable|integer|min:0',
            'distance_km' => 'nullable|numeric|min:0',
            'is_warmup' => 'boolean',
            'is_completed' => 'boolean',
        ]);

        $set->update($validated);

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
