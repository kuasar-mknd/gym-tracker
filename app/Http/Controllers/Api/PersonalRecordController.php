<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PersonalRecordResource;
use App\Models\PersonalRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class PersonalRecordController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PersonalRecord::query()->where('user_id', Auth::id());

        if ($request->has('exercise_id')) {
            $query->where('exercise_id', $request->input('exercise_id'));
        }

        $query->with('exercise');

        return PersonalRecordResource::collection($query->paginate());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): PersonalRecordResource
    {
        $validated = $request->validate([
            'exercise_id' => 'required|exists:exercises,id',
            'type' => 'required|string',
            'value' => 'required|numeric',
            'secondary_value' => 'nullable|numeric',
            'workout_id' => 'nullable|exists:workouts,id',
            'set_id' => 'nullable|exists:sets,id',
            'achieved_at' => 'required|date',
        ]);

        $personalRecord = PersonalRecord::create([
            ...$validated,
            'user_id' => Auth::id(),
        ]);

        return new PersonalRecordResource($personalRecord);
    }

    /**
     * Display the specified resource.
     */
    public function show(PersonalRecord $personalRecord): PersonalRecordResource
    {
        if ($personalRecord->user_id !== Auth::id()) {
            abort(403);
        }

        return new PersonalRecordResource($personalRecord->load('exercise'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, PersonalRecord $personalRecord): PersonalRecordResource
    {
        if ($personalRecord->user_id !== Auth::id()) {
            abort(403);
        }

        $validated = $request->validate([
            'exercise_id' => 'sometimes|exists:exercises,id',
            'type' => 'sometimes|string',
            'value' => 'sometimes|numeric',
            'secondary_value' => 'nullable|numeric',
            'workout_id' => 'nullable|exists:workouts,id',
            'set_id' => 'nullable|exists:sets,id',
            'achieved_at' => 'sometimes|date',
        ]);

        $personalRecord->update($validated);

        return new PersonalRecordResource($personalRecord);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PersonalRecord $personalRecord): \Illuminate\Http\Response
    {
        if ($personalRecord->user_id !== Auth::id()) {
            abort(403);
        }

        $personalRecord->delete();

        return response()->noContent();
    }
}
