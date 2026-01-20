<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreHabitRequest;
use App\Http\Requests\Api\UpdateHabitRequest;
use App\Http\Resources\HabitResource;
use App\Models\Habit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class HabitController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $habits = QueryBuilder::for(Habit::class)
            ->where('user_id', $request->user()->id)
            ->allowedFilters([
                'name',
                AllowedFilter::exact('archived'),
            ])
            ->allowedSorts(['name', 'created_at', 'goal_times_per_week'])
            ->allowedIncludes(['logs'])
            ->defaultSort('-created_at')
            ->paginate($request->get('per_page', 15));

        return HabitResource::collection($habits);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreHabitRequest $request)
    {
        $data = $request->validated();

        if (empty($data['color'])) {
            $data['color'] = 'bg-slate-500';
        }
        if (empty($data['icon'])) {
            $data['icon'] = 'check_circle';
        }

        $habit = $request->user()->habits()->create($data);

        return (new HabitResource($habit))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Habit $habit)
    {
        if ($habit->user_id !== auth()->id()) {
            abort(403);
        }

        $habit->load(['logs' => function ($query) {
            $query->latest('date')->limit(10);
        }]);

        return new HabitResource($habit);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateHabitRequest $request, Habit $habit)
    {
        $habit->update($request->validated());

        return new HabitResource($habit);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Habit $habit)
    {
        if ($habit->user_id !== auth()->id()) {
            abort(403);
        }

        $habit->delete();

        return response()->noContent();
    }
}
