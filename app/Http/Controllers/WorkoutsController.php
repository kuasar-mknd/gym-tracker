<?php

namespace App\Http\Controllers;

use App\Actions\Workouts\FetchWorkoutsIndexAction;
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

    public function __construct(protected \App\Services\StatsService $statsService) {}

    /**
     * Display a listing of the user's workouts.
     *
     * Retrieves all workouts for the authenticated user, eager loading
     * related workout lines, exercises, and sets.
     *
     * @return \Inertia\Response The Inertia response rendering the Workouts/Index page.
     */
    public function index(Request $request, FetchWorkoutsIndexAction $fetchWorkouts): \Inertia\Response
    {
        $this->authorize('viewAny', Workout::class);

        $data = $fetchWorkouts->execute($request->user());

        return Inertia::render('Workouts/Index', $data);
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
        // Security: Filter exercises by user to prevent information disclosure
        $userId = auth()->id();
        $exercises = Cache::remember("exercises_list_{$userId}", 3600, function () use ($userId) {
            return Exercise::forUser($userId)->orderBy('name')->get();
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

        $this->statsService->clearUserStatsCache(auth()->user());

        return redirect()->route('workouts.show', $workout);
    }

    /**
     * Update the specified workout in storage.
     */
    public function update(Request $request, Workout $workout): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $workout);

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'is_finished' => 'nullable|boolean',
        ]);

        if (isset($validated['name'])) {
            $workout->name = $validated['name'];
        }

        if (isset($validated['notes'])) {
            $workout->notes = $validated['notes'];
        }

        if (! empty($validated['is_finished']) && $validated['is_finished']) {
            $workout->ended_at = now();
        }

        $workout->save();

        $this->statsService->clearUserStatsCache(auth()->user());

        return back();
    }

    /**
     * Remove the specified workout from storage.
     */
    public function destroy(Workout $workout): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $workout);

        $workout->delete();

        $this->statsService->clearUserStatsCache(auth()->user());

        return redirect()->route('workouts.index');
    }
}
