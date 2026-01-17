<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DailyJournalStoreRequest;
use App\Http\Requests\DailyJournalUpdateRequest;
use App\Http\Resources\DailyJournalResource;
use App\Models\DailyJournal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DailyJournalController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', DailyJournal::class);

        $journals = $request->user()->dailyJournals()
            ->orderBy('date', 'desc')
            ->paginate(30);

        return DailyJournalResource::collection($journals);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(DailyJournalStoreRequest $request)
    {
        $this->authorize('create', DailyJournal::class);

        $validated = $request->validated();

        // Check if journal already exists for this date
        $date = \Carbon\Carbon::parse($validated['date'])->format('Y-m-d');
        if ($request->user()->dailyJournals()->where('date', $date)->exists()) {
            return response()->json([
                'message' => 'A journal entry for this date already exists.',
                'errors' => [
                    'date' => ['A journal entry for this date already exists.']
                ]
            ], 422);
        }

        $journal = $request->user()->dailyJournals()->create($validated);

        return new DailyJournalResource($journal);
    }

    /**
     * Display the specified resource.
     */
    public function show(DailyJournal $dailyJournal)
    {
        $this->authorize('view', $dailyJournal);

        return new DailyJournalResource($dailyJournal);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(DailyJournalUpdateRequest $request, DailyJournal $dailyJournal)
    {
        $validated = $request->validated();

        // If date is being changed, ensure it doesn't conflict
        if (isset($validated['date'])) {
             $date = \Carbon\Carbon::parse($validated['date'])->format('Y-m-d');
             // If date is different from current
             if ($dailyJournal->date && $dailyJournal->date->format('Y-m-d') !== $date) {
                 if ($request->user()->dailyJournals()->where('date', $date)->exists()) {
                     return response()->json([
                         'message' => 'A journal entry for this date already exists.',
                         'errors' => [
                             'date' => ['A journal entry for this date already exists.']
                         ]
                     ], 422);
                 }
             }
        }

        $dailyJournal->update($validated);

        return new DailyJournalResource($dailyJournal);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DailyJournal $dailyJournal)
    {
        $this->authorize('delete', $dailyJournal);

        $dailyJournal->delete();

        return response()->noContent();
    }
}
