<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateWorkoutFromTemplateAction;
use App\Actions\CreateWorkoutTemplateAction;
use App\Actions\CreateWorkoutTemplateFromWorkoutAction;
use App\Models\Exercise;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

/**
 * Controller for managing Workout Templates.
 *
 * This controller handles the creation, retrieval, and deletion of workout templates.
 * It also facilitates the creation of new workouts based on existing templates
 * and the creation of templates from existing workouts.
 */
class WorkoutTemplatesController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the user's workout templates.
     *
     * Retrieves all templates belonging to the authenticated user, including
     * their exercise lines and set details.
     *
     * @return \Inertia\Response The Inertia response rendering the Templates index page.
     */
    public function index(): \Inertia\Response
    {
        $this->authorize('viewAny', WorkoutTemplate::class);

        return Inertia::render('Workouts/Templates/Index', [
            'templates' => WorkoutTemplate::with(['workoutTemplateLines.exercise', 'workoutTemplateLines.workoutTemplateSets'])
                ->where('user_id', $this->user()->id)
                ->latest()
                ->get(),
        ]);
    }

    /**
     * Show the form for creating a new workout template.
     *
     * Preloads the user's exercises cache to populate the exercise selector in the form.
     *
     * @return \Inertia\Response The Inertia response rendering the Template creation page.
     */
    public function create(): \Inertia\Response
    {
        $this->authorize('create', WorkoutTemplate::class);

        $userId = $this->user()->id;

        return Inertia::render('Workouts/Templates/Create', [
            'exercises' => Exercise::getCachedForUser($userId),
        ]);
    }

    /**
     * Store a newly created workout template in storage.
     *
     * Validates the request data and uses the CreateWorkoutTemplateAction to persist
     * the template and its associated lines/sets.
     *
     * @param  \App\Http\Requests\StoreWorkoutTemplateRequest  $request  The validated request containing template data.
     * @param  \App\Actions\CreateWorkoutTemplateAction  $createWorkoutTemplateAction  Action to handle template creation logic.
     * @return \Illuminate\Http\RedirectResponse Redirects to the templates index page.
     */
    public function store(\App\Http\Requests\StoreWorkoutTemplateRequest $request, CreateWorkoutTemplateAction $createWorkoutTemplateAction): \Illuminate\Http\RedirectResponse
    {
        /** @var array{name: string, description?: string|null, exercises?: array<int, array{id: int, sets?: array<int, array{reps?: int|null, weight?: float|null, is_warmup?: bool}>}>} $validated */
        $validated = $request->validated();
        $createWorkoutTemplateAction->execute($this->user(), $validated);

        return redirect()->route('templates.index');
    }

    /**
     * Execute a workout template to start a new workout.
     *
     * Creates a new active workout session based on the provided template
     * using the CreateWorkoutFromTemplateAction.
     *
     * @param  \App\Models\WorkoutTemplate  $template  The template to use.
     * @param  \App\Actions\CreateWorkoutFromTemplateAction  $createWorkout  Action to create the workout from the template.
     * @return \Illuminate\Http\RedirectResponse Redirects to the show page of the newly created workout.
     */
    public function execute(WorkoutTemplate $template, CreateWorkoutFromTemplateAction $createWorkout): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('view', $template);

        $workout = $createWorkout->execute($this->user(), $template);

        return redirect()->route('workouts.show', $workout);
    }

    /**
     * Save an existing workout as a new template.
     *
     * Allows users to save a completed or in-progress workout as a template
     * for future reuse.
     *
     * @param  \App\Models\Workout  $workout  The workout to base the template on.
     * @param  \App\Actions\CreateWorkoutTemplateFromWorkoutAction  $createTemplate  Action to create the template from the workout.
     * @return \Illuminate\Http\RedirectResponse Redirects to the templates index with a success message.
     */
    public function saveFromWorkout(Workout $workout, CreateWorkoutTemplateFromWorkoutAction $createTemplate): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('view', $workout);

        $createTemplate->execute($this->user(), $workout);

        return redirect()->route('templates.index')->with('success', 'Modèle enregistré avec succès !');
    }

    /**
     * Remove the specified workout template from storage.
     *
     * @param  \App\Models\WorkoutTemplate  $template  The template to delete.
     * @return \Illuminate\Http\RedirectResponse Redirects back to the previous page.
     */
    public function destroy(WorkoutTemplate $template): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $template);

        $template->delete();

        return back();
    }
}
