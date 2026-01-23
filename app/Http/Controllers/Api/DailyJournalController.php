<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DailyJournalStoreRequest;
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

        /** @var int $perPage */
        $perPage = $request->get('per_page', 15);

        $journals = $this->user()->dailyJournals()
            ->orderBy('date', 'desc')
            ->paginate($perPage);

        return DailyJournalResource::collection($journals);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(DailyJournalStoreRequest $request): DailyJournalResource
    {
        $this->authorize('create', DailyJournal::class);

        $validated = $request->validated();

        $journal = new DailyJournal($validated);
        $journal->user_id = $this->user()->id;
        $journal->save();

        return new DailyJournalResource($journal);
    }

    /**
     * Display the specified resource.
     */
    public function show(DailyJournal $dailyJournal): DailyJournalResource
    {
        $this->authorize('view', $dailyJournal);

        return new DailyJournalResource($dailyJournal);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(DailyJournalUpdateRequest $request, DailyJournal $dailyJournal): DailyJournalResource
    {
        $this->authorize('update', $dailyJournal);

        $dailyJournal->update($request->validated());

        return new DailyJournalResource($dailyJournal);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DailyJournal $dailyJournal): \Illuminate\Http\Response
    {
        $this->authorize('delete', $dailyJournal);

        $dailyJournal->delete();

        return response()->noContent();
    }
}
