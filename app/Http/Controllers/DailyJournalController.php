<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Journal\SaveDailyJournalAction;
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
            ->limit(100)
            ->get();

        return Inertia::render('Journal/Index', [
            'journals' => $journals,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(DailyJournalStoreRequest $request, SaveDailyJournalAction $saveDailyJournal): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', DailyJournal::class);

        $saveDailyJournal->execute($this->user(), $request->validated());

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
