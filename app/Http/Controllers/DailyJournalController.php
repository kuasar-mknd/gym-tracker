<?php

namespace App\Http\Controllers;

use App\Http\Requests\DailyJournalStoreRequest;
use App\Models\DailyJournal;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class DailyJournalController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
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
        $validated = $request->validated();

        // Use updateOrCreate to handle editing existing entry for the day
        $date = \Carbon\Carbon::parse($validated['date'])->format('Y-m-d');

        DailyJournal::updateOrCreate(
            ['user_id' => $request->user()->id, 'date' => $date],
            $validated
        );

        return redirect()->back();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DailyJournal $dailyJournal)
    {
        if ($dailyJournal->user_id !== Auth::id()) {
            abort(403);
        }

        $dailyJournal->delete();

        return redirect()->back();
    }
}
