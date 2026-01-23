<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DailyJournalStoreRequest;
use App\Models\DailyJournal;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Inertia\Inertia;

class DailyJournalController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the resource.
     */
    public function index(): \Inertia\Response
    {
        $this->authorize('viewAny', DailyJournal::class);

        $journals = $this->user()->dailyJournals()
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
    public function store(DailyJournalStoreRequest $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', DailyJournal::class);

        $validated = $request->validated();
        $dateInput = $validated['date'];
        if (! is_string($dateInput)) {
            throw new \UnexpectedValueException('Date must be a string');
        }
        $date = \Illuminate\Support\Carbon::parse($dateInput);
        $dateString = $date->format('Y-m-d');

        $journal = $this->user()->dailyJournals()->where('date', $dateString)->first() ?? new DailyJournal;

        if (! $journal->exists) {
            $journal->user_id = $this->user()->id;
            $journal->date = $date;
        }

        $journal->fill($validated);
        $journal->save();

        return redirect()->back();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DailyJournal $dailyJournal): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $dailyJournal);

        $dailyJournal->delete();

        return redirect()->back();
    }
}
