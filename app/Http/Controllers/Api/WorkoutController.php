<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\WorkoutResource;
use App\Models\Workout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;

class WorkoutController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    #[OA\Get(
        path: '/workouts',
        summary: 'Get list of workouts',
        tags: ['Workouts']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function index()
    {
        $workouts = \Spatie\QueryBuilder\QueryBuilder::for(Workout::class)
            ->allowedIncludes(['workoutLines', 'workoutLines.exercise', 'workoutLines.sets'])
            ->allowedSorts(['started_at', 'ended_at', 'created_at'])
            ->defaultSort('-started_at')
            ->where('user_id', Auth::id())
            ->paginate();

        return WorkoutResource::collection($workouts);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'started_at' => 'nullable|date',
            'ended_at' => 'nullable|date|after_or_equal:started_at',
            'notes' => 'nullable|string',
        ]);

        $workout = new Workout($validated);
        $workout->user_id = Auth::id();
        $workout->save();

        return new WorkoutResource($workout);
    }

    /**
     * Display the specified resource.
     */
    public function show(Workout $workout)
    {
        if ($workout->user_id !== Auth::id()) {
            abort(403);
        }

        return new WorkoutResource($workout);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Workout $workout)
    {
        if ($workout->user_id !== Auth::id()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'started_at' => 'nullable|date',
            'ended_at' => 'nullable|date|after_or_equal:started_at',
            'notes' => 'nullable|string',
        ]);

        $workout->update($validated);

        return new WorkoutResource($workout);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Workout $workout)
    {
        if ($workout->user_id !== Auth::id()) {
            abort(403);
        }

        $workout->delete();

        return response()->noContent();
    }
}
