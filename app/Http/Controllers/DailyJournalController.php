<?php

namespace App\Http\Controllers;

use App\Http\Requests\DailyJournalStoreRequest;
use App\Models\DailyJournal;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class DailyJournalController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $this->authorize('viewAny', DailyJournal::class);

        $journals = Auth::user()->dailyJournals()
            ->orderBy('date', 'desc')
            ->limit(30) // Show last 30 days
            ->get();

        return Inertia::render('Journal/Index', [
            'journals' => $journals,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(DailyJournalStoreRequest $request)
    {
        $this->authorize('create', DailyJournal::class);

        $validated = $request->validated();
        $date = \Carbon\Carbon::parse($validated['date'])->format('Y-m-d');

        $journal = $request->user()->dailyJournals()->where('date', $date)->first() ?? new DailyJournal;

        if (! $journal->exists) {
            $journal->user_id = $request->user()->id;
            $journal->date = $date;
        }

        $journal->fill($validated);
        $journal->save();

        return redirect()->back();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DailyJournal $dailyJournal)
    {
        $this->authorize('delete', $dailyJournal);

        $dailyJournal->delete();

        return redirect()->back();
    }
}
