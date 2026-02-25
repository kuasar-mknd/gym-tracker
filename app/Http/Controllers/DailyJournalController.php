<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Journal\LogDailyJournalAction;
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
    public function store(DailyJournalStoreRequest $request, LogDailyJournalAction $logDailyJournal): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', DailyJournal::class);

        /** @var array{date: string, content?: string|null, mood_score?: int|null, sleep_quality?: int|null, stress_level?: int|null, energy_level?: int|null, motivation_level?: int|null, nutrition_score?: int|null, training_intensity?: int|null} $validated */
        $validated = $request->validated();

        $logDailyJournal->execute($this->user(), $validated);

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
