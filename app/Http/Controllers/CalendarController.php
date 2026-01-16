<?php

namespace App\Http\Controllers;

use App\Models\DailyJournal;
use App\Models\Workout;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CalendarController extends Controller
{
    public function index(Request $request)
    {
        $year = $request->input('year', now()->year);
        $month = $request->input('month', now()->month);

        $date = Carbon::createFromDate($year, $month, 1);
        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth = $date->copy()->endOfMonth();

        // Fetch Workouts
        $workouts = Workout::where('user_id', Auth::id())
            ->whereBetween('started_at', [$startOfMonth, $endOfMonth])
            ->with(['workoutLines.exercise']) // Eager load for quick preview
            ->get()
            ->map(function ($workout) {
                return [
                    'id' => $workout->id,
                    'name' => $workout->name ?? 'Séance',
                    'date' => $workout->started_at->toDateString(),
                    'started_at' => $workout->started_at->toIso8601String(),
                    'exercises_count' => $workout->workoutLines->count(),
                    'preview_exercises' => $workout->workoutLines->take(3)->map(fn ($line) => $line->exercise->name)->toArray(),
                ];
            });

        // Fetch Journals
        $journals = DailyJournal::where('user_id', Auth::id())
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->get()
            ->map(function ($journal) {
                return [
                    'id' => $journal->id,
                    'date' => $journal->date->toDateString(),
                    'mood_score' => $journal->mood_score,
                    'has_note' => !empty($journal->content),
                ];
            });

        return Inertia::render('Calendar/Index', [
            'year' => (int) $year,
            'month' => (int) $month,
            'workouts' => $workouts,
            'journals' => $journals,
        ]);
    }
}
