<?php

namespace App\Http\Controllers;

use App\Models\DailyJournal;
use Illuminate\Http\Request;
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
    public function store(Request $request)
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'content' => ['nullable', 'string', 'max:5000'],
            'mood_score' => ['nullable', 'integer', 'min:1', 'max:5'],
            'sleep_quality' => ['nullable', 'integer', 'min:1', 'max:5'],
            'stress_level' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        // Use updateOrCreate to handle editing existing entry for the day
        $request->user()->dailyJournals()->updateOrCreate(
            ['date' => $validated['date']],
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
