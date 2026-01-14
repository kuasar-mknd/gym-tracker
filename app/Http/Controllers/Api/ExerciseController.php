<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ExerciseResource;
use App\Models\Exercise;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ExerciseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    #[OA\Get(
        path: '/exercises',
        summary: 'Get list of exercises',
        tags: ['Exercises']
    )]
    #[OA\Response(response: 200, description: 'Successful operation')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function index()
    {
        $exercises = \Spatie\QueryBuilder\QueryBuilder::for(Exercise::class)
            ->allowedFilters(['name', 'type', 'category'])
            ->allowedSorts(['name', 'created_at'])
            ->defaultSort('name')
            ->paginate();

        return ExerciseResource::collection($exercises);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:strength,cardio,timed',
            'category' => 'nullable|string|max:255',
        ]);

        $exercise = Exercise::create($validated);

        return new ExerciseResource($exercise);
    }

    /**
     * Display the specified resource.
     */
    public function show(Exercise $exercise)
    {
        return new ExerciseResource($exercise);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Exercise $exercise)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:strength,cardio,timed',
            'category' => 'nullable|string|max:255',
        ]);

        $exercise->update($validated);

        return new ExerciseResource($exercise);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Exercise $exercise)
    {
        $exercise->delete();

        return response()->noContent();
    }
}
