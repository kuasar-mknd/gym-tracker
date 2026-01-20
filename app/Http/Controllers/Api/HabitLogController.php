<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreHabitLogRequest;
use App\Http\Resources\HabitLogResource;
use App\Models\HabitLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class HabitLogController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $logs = QueryBuilder::for(HabitLog::class)
            ->whereHas('habit', function ($query) use ($request) {
                $query->where('user_id', $request->user()->id);
            })
            ->allowedFilters([
                'habit_id',
                AllowedFilter::scope('date_between', 'dateBetween'), // Assuming scope exists or using exact
                'date',
            ])
            ->allowedSorts(['date', 'created_at'])
            ->defaultSort('-date')
            ->paginate($request->get('per_page', 15));

        return HabitLogResource::collection($logs);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreHabitLogRequest $request)
    {
        // Check for existing log to prevent duplicates if desired, or allow multiple notes per day?
        // The original HabitController::toggle logic allows one per day.
        // Let's enforce unique(habit_id, date) if that's the business rule, or just create.
        // The original toggle logic does: $habit->logs()->whereDate('date', $date)->first()
        // Here we'll stick to standard create.

        $log = HabitLog::create($request->validated());

        return (new HabitLogResource($log))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(HabitLog $habitLog)
    {
        if ($habitLog->habit->user_id !== auth()->id()) {
            abort(403);
        }

        return new HabitLogResource($habitLog);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(HabitLog $habitLog)
    {
        if ($habitLog->habit->user_id !== auth()->id()) {
            abort(403);
        }

        $habitLog->delete();

        return response()->noContent();
    }
}
