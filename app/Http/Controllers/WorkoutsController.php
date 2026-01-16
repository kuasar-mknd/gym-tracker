<?php

namespace App\Http\Controllers;

use App\Models\Exercise;
use App\Models\Workout;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

/**
 * Controller for managing Workouts.
 *
 * This controller handles the CRUD operations for workouts, including
 * listing user's workouts, displaying a specific workout, and creating a new one.
 * It integrates with Inertia.js for the frontend.
 */
class WorkoutsController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the user's workouts.
     *
     * Retrieves all workouts for the authenticated user, eager loading
     * related workout lines, exercises, and sets.
     *
     * @return \Inertia\Response The Inertia response rendering the Workouts/Index page.
     */
    public function index(): \Inertia\Response
    {
        $this->authorize('viewAny', Workout::class);

        // Get last 6 months frequency
        $monthlyFrequency = Workout::select('started_at')
            ->where('user_id', auth()->id())
            ->where('started_at', '>=', now()->subMonths(5)->startOfMonth())
            ->orderBy('started_at')
            ->get()
            ->groupBy(fn ($workout) => $workout->started_at->format('Y-m'))
            ->map(fn ($workouts, $month) => [
                'month' => \Carbon\Carbon::createFromFormat('Y-m', $month)->format('M'),
                'count' => $workouts->count(),
            ])
            ->values();

        // Get duration history for the last 20 workouts
        $durationHistory = Workout::select('name', 'started_at', 'ended_at')
            ->where('user_id', auth()->id())
            ->whereNotNull('ended_at')
            ->latest('started_at')
            ->take(20)
            ->get()
            ->map(function ($workout) {
                return [
                    'date' => $workout->started_at->format('d/m'),
                    'duration' => $workout->ended_at->diffInMinutes($workout->started_at),
                    'name' => $workout->name,
                ];
            })
            ->reverse()
            ->values();

        // NITRO FIX: Paginate workouts instead of loading all
        return Inertia::render('Workouts/Index', [
            'workouts' => Workout::with(['workoutLines.exercise', 'workoutLines.sets'])
                ->where('user_id', auth()->id())
                ->latest('started_at')
                ->paginate(20),
            'monthlyFrequency' => $monthlyFrequency,
            'durationHistory' => $durationHistory,
        ]);
    }

    /**
     * Display the specified workout.
     *
     * Shows the details of a specific workout, including its exercises and sets.
     * Ensures that the authenticated user owns the workout.
     *
     * @param  \App\Models\Workout  $workout  The workout to display.
     * @return \Inertia\Response The Inertia response rendering the Workouts/Show page.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException If the user is not authorized to view the workout (403).
     */
    public function show(Workout $workout): \Inertia\Response
    {
        $this->authorize('view', $workout);

        // NITRO FIX: Cache exercises list for 1 hour
        $exercises = Cache::remember('exercises_list_'.auth()->id(), 3600, function () {
            return Exercise::where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', auth()->id()))
                ->orderBy('name')
                ->get();
        });

        return Inertia::render('Workouts/Show', [
            'workout' => $workout->load(['workoutLines.exercise', 'workoutLines.sets.personalRecord']),
            'exercises' => $exercises,
            'categories' => ['Pectoraux', 'Dos', 'Jambes', 'Épaules', 'Bras', 'Abdominaux', 'Cardio'],
            'types' => [
                ['value' => 'strength', 'label' => 'Force'],
                ['value' => 'cardio', 'label' => 'Cardio'],
                ['value' => 'timed', 'label' => 'Temps'],
            ],
        ]);
    }

    /**
     * Store a newly created workout in storage.
     *
     * Creates a new workout for the authenticated user with the current date
     * as the start date and a default name. Redirects to the show page of the new workout.
     *
     * @param  \Illuminate\Http\Request  $request  The HTTP request (currently unused for input but part of the signature).
     * @return \Illuminate\Http\RedirectResponse A redirect to the newly created workout.
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', Workout::class);

        $workout = new Workout([
            'started_at' => now(),
            'name' => 'Séance du '.now()->format('d/m/Y'),
        ]);
        $workout->user_id = auth()->id();
        $workout->save();

        return redirect()->route('workouts.show', $workout);
    }
}
