<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Workouts\CreateWorkoutAction;
use App\Actions\Workouts\FetchWorkoutShowAction;
use App\Actions\Workouts\FetchWorkoutsIndexAction;
use App\Actions\Workouts\UpdateWorkoutAction;
use App\Http\Requests\UpdateWorkoutRequest;
use App\Models\Exercise;
use App\Models\Workout;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
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

    public function __construct(protected \App\Services\StatsService $statsService)
    {
    }

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

        $user = $this->user();
        $data = $fetchWorkouts->execute($user);
        $userId = $user->id;

        return Inertia::render('Workouts/Index', [
            ...$data,
            // ⚡ Bolt: PERFORMANCE OPTIMIZATION
            // Consolidate heavy chart data into a single deferred prop to reduce the number
            // of XHR requests and ensure consistent loading states on the frontend.
            'chartData' => Inertia::defer(fn (): array => $fetchWorkouts->getChartData($user)),
            'exercises' => Inertia::defer(fn (): \Illuminate\Database\Eloquent\Collection => Exercise::getCachedForUser($userId)),
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
    public function show(Workout $workout, FetchWorkoutShowAction $fetchWorkoutShow): \Inertia\Response
    {
        $this->authorize('view', $workout);

        return Inertia::render('Workouts/Show', $fetchWorkoutShow->execute($this->user(), $workout));
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
    public function store(Request $request, CreateWorkoutAction $createWorkout): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', Workout::class);

        $workout = $createWorkout->execute($this->user());

        return redirect()->route('workouts.show', $workout);
    }

    /**
     * Update the specified workout in storage.
     */
    public function update(UpdateWorkoutRequest $request, Workout $workout, UpdateWorkoutAction $updateWorkout): \Illuminate\Http\RedirectResponse
    {
        /** @var array{started_at?: string|null, name?: string|null, notes?: string|null, is_finished?: bool} $data */
        $data = $request->validated();
        $updateWorkout->execute($workout, $data);

        if ($request->boolean('is_finished')) {
            return redirect()->route('dashboard');
        }

        return back();
    }

    /**
     * Remove the specified workout from storage.
     */
    public function destroy(Workout $workout): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $workout);

        $workout->delete();

        $this->statsService->clearWorkoutRelatedStats($this->user());
        $this->statsService->clearWorkoutMetadataStats($this->user());

        return redirect()->route('workouts.index');
    }
}
