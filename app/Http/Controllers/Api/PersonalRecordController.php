<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PersonalRecordStoreRequest;
use App\Http\Requests\PersonalRecordUpdateRequest;
use App\Http\Resources\PersonalRecordResource;
use App\Models\PersonalRecord;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PersonalRecordController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PersonalRecord::class);

        $validated = $request->validate([
            'exercise_id' => 'nullable|integer|exists:exercises,id',
        ]);

        $query = PersonalRecord::query()->where('user_id', $this->user()->id);

        if (isset($validated['exercise_id'])) {
            $query->where('exercise_id', $validated['exercise_id']);
        }

        $query->with('exercise');

        return PersonalRecordResource::collection($query->paginate());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(PersonalRecordStoreRequest $request): PersonalRecordResource
    {
        $validated = $request->validated();

        $personalRecord = new PersonalRecord;
        $personalRecord->fill($validated);
        $personalRecord->user_id = $this->user()->id;
        $personalRecord->save();

        return new PersonalRecordResource($personalRecord);
    }

    /**
     * Display the specified resource.
     */
    public function show(PersonalRecord $personalRecord): PersonalRecordResource
    {
        $this->authorize('view', $personalRecord);

        return new PersonalRecordResource($personalRecord->load('exercise'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(PersonalRecordUpdateRequest $request, PersonalRecord $personalRecord): PersonalRecordResource
    {
        $validated = $request->validated();

        $personalRecord->update($validated);

        return new PersonalRecordResource($personalRecord);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PersonalRecord $personalRecord): \Illuminate\Http\Response
    {
        $this->authorize('delete', $personalRecord);

        $personalRecord->delete();

        return response()->noContent();
    }
}
