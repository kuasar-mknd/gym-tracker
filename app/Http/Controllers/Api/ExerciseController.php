<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\ExerciseStoreRequest;
use App\Http\Requests\ExerciseUpdateRequest;
use App\Http\Resources\ExerciseResource;
use App\Models\Exercise;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

class ExerciseController extends Controller
{
    use AuthorizesRequests;

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
    public function index(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', Exercise::class);

        $exercises = \Spatie\QueryBuilder\QueryBuilder::for(Exercise::class)
            ->allowedFilters(['name', 'type', 'category'])
            ->allowedSorts(['name', 'created_at'])
            ->defaultSort('name')
            ->where(function ($query): void {
                $query->whereNull('user_id')
                    ->orWhere('user_id', $this->user()->id);
            })
            ->paginate();

        return ExerciseResource::collection($exercises);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ExerciseStoreRequest $request): ExerciseResource
    {
        $validated = $request->validated();

        $exercise = new Exercise($validated);
        $exercise->user_id = $this->user()->id;
        $exercise->save();

        Cache::forget("exercises_list_{$this->user()->id}");

        return new ExerciseResource($exercise);
    }

    /**
     * Display the specified resource.
     */
    public function show(Exercise $exercise): ExerciseResource
    {
        $this->authorize('view', $exercise);

        return new ExerciseResource($exercise);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ExerciseUpdateRequest $request, Exercise $exercise): ExerciseResource
    {
        $validated = $request->validated();

        $exercise->update($validated);

        Cache::forget("exercises_list_{$this->user()->id}");

        return new ExerciseResource($exercise);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Exercise $exercise): \Illuminate\Http\Response
    {
        $this->authorize('delete', $exercise);

        $exercise->delete();

        Cache::forget("exercises_list_{$this->user()->id}");

        return response()->noContent();
    }
}
