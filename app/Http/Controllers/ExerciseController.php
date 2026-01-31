<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ExerciseStoreRequest;
use App\Http\Requests\ExerciseUpdateRequest;
use App\Models\Exercise;
use App\Services\StatsService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Inertia\Inertia;

/**
 * Controller for managing Exercises.
 *
 * This controller handles CRUD operations for exercises.
 * It manages the user's exercise library, supports categorization,
 * and handles cache invalidation for exercise lists.
 * It supports both standard Inertia responses and JSON responses for quick creation.
 */
class ExerciseController extends Controller
{
    use AuthorizesRequests;

    public function show(Exercise $exercise, StatsService $statsService): \Inertia\Response
    {
        $this->authorize('view', $exercise);

        $progress = $statsService->getExercise1RMProgress($this->user(), $exercise->id, 365);

        // Fetch history
        $history = $exercise->workoutLines()
            ->with(['workout' => function ($query) {
                $query->select('id', 'name', 'started_at', 'ended_at');
            }, 'sets'])
            ->whereHas('workout', function ($query) {
                $query->where('user_id', $this->user()->id)
                    ->whereNotNull('ended_at');
            })
            ->get()
            ->sortByDesc('workout.started_at')
            ->values()
            ->map(function ($line) {
                return [
                    'id' => $line->id,
                    'workout_id' => $line->workout->id,
                    'workout_name' => $line->workout->name,
                    'date' => $line->workout->started_at->format('Y-m-d'),
                    'formatted_date' => $line->workout->started_at->format('d/m/Y'),
                    'sets' => $line->sets->map(function ($set) {
                        return [
                            'weight' => $set->weight,
                            'reps' => $set->reps,
                            '1rm' => $set->weight * (1 + $set->reps / 30),
                        ];
                    }),
                    'best_1rm' => $line->sets->max(fn ($set) => $set->weight * (1 + $set->reps / 30)),
                ];
            });

        return Inertia::render('Exercises/Show', [
            'exercise' => $exercise,
            'progress' => $progress,
            'history' => $history,
        ]);
    }

    /**
     * Display a listing of the user's exercises.
     *
     * Retrieves all exercises for the authenticated user, ordered by category and name.
     * Returns an Inertia response with the exercises list and available metadata (categories, types).
     *
     * @return \Inertia\Response The Inertia response rendering the Exercises/Index page.
     */
    public function index(): \Inertia\Response
    {
        $this->authorize('viewAny', Exercise::class);

        $exercises = Exercise::getCachedForUser($this->user()->id);

        return Inertia::render('Exercises/Index', [
            'exercises' => $exercises,
            'categories' => ['Pectoraux', 'Dos', 'Jambes', 'Épaules', 'Bras', 'Abdominaux', 'Cardio'],
            'types' => [
                ['value' => 'strength', 'label' => 'Force (poids)'],
                ['value' => 'cardio', 'label' => 'Cardio (distance)'],
                ['value' => 'timed', 'label' => 'Temps'],
            ],
        ]);
    }

    /**
     * Store a newly created exercise in storage.
     *
     * Validates and creates a new exercise for the authenticated user.
     * Invalidates the 'exercises_list_{userId}' cache.
     * Returns JSON if requested (e.g., from a workout creation modal) or redirects back.
     *
     * @param  \App\Http\Requests\ExerciseStoreRequest  $request  The validated request containing name, type, and category.
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse JSON response with the created exercise or a redirect back.
     */
    public function store(ExerciseStoreRequest $request): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
        // Auth check handled by middleware/request

        $data = $request->validated();
        $exercise = new Exercise($data);
        $exercise->user_id = $this->user()->id;
        $exercise->save();

        // Return JSON for AJAX requests (from workout page), redirect for regular form submissions
        if ($request->wantsJson() || $request->header('X-Quick-Create')) {
            return response()->json(['exercise' => $exercise], 201);
        }

        return redirect()->back();
    }

    /**
     * Update the specified exercise in storage.
     *
     * Updates the exercise details and invalidates the user's exercise cache.
     *
     * @param  \App\Http\Requests\ExerciseUpdateRequest  $request  The validated request containing updated fields.
     * @param  \App\Models\Exercise  $exercise  The exercise to update.
     * @return \Illuminate\Http\RedirectResponse A redirect back to the previous page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update this exercise.
     */
    public function update(ExerciseUpdateRequest $request, Exercise $exercise): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $exercise);

        $exercise->update($request->validated());

        return redirect()->back();
    }

    /**
     * Remove the specified exercise from storage.
     *
     * Deletes the exercise if it is not linked to any existing workout lines.
     * Invalidates the user's exercise cache upon successful deletion.
     *
     * @param  \App\Models\Exercise  $exercise  The exercise to delete.
     * @return \Illuminate\Http\RedirectResponse A redirect back with potential error messages if deletion fails.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete this exercise.
     */
    public function destroy(Exercise $exercise): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $exercise);

        if ($exercise->workoutLines()->exists()) {
            return redirect()->back()->withErrors([
                'exercise' => 'Cet exercice est utilisé dans une séance et ne peut pas être supprimé.',
            ]);
        }

        $exercise->delete();

        return redirect()->back();
    }
}
