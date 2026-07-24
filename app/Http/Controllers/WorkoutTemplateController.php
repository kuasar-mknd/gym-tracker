<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateWorkoutFromTemplateAction;
use App\Actions\CreateWorkoutTemplateAction;
use App\Actions\CreateWorkoutTemplateFromWorkoutAction;
use App\Actions\FetchWorkoutTemplatesAction;
use App\Http\Requests\StoreWorkoutTemplateRequest;
use App\Models\Exercise;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing Workout Templates.
 *
 * This controller handles the creation, retrieval, and deletion of workout templates.
 * It also facilitates the creation of new workouts based on existing templates
 * and the creation of templates from existing workouts.
 */
class WorkoutTemplateController extends Controller
{
    /**
     * Display a listing of the user's workout templates.
     *
     * Retrieves all templates belonging to the authenticated user, including
     * their exercise lines and set details.
     *
     * @return Response The Inertia response rendering the Templates index page.
     */
    public function index(FetchWorkoutTemplatesAction $fetchWorkoutTemplatesAction): Response
    {
        $this->authorize('viewAny', WorkoutTemplate::class);

        return Inertia::render('Workouts/Templates/Index', [
            'templates' => $fetchWorkoutTemplatesAction->execute($this->user()),
        ]);
    }

    /**
     * Show the form for creating a new workout template.
     *
     * Preloads the user's exercises cache to populate the exercise selector in the form.
     *
     * @return Response The Inertia response rendering the Template creation page.
     */
    public function create(): Response
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
     * @param  StoreWorkoutTemplateRequest  $request  The validated request containing template data.
     * @param  CreateWorkoutTemplateAction  $createWorkoutTemplateAction  Action to handle template creation logic.
     * @return RedirectResponse Redirects to the templates index page.
     */
    public function store(StoreWorkoutTemplateRequest $request, CreateWorkoutTemplateAction $createWorkoutTemplateAction): RedirectResponse
    {
        $this->authorize('create', WorkoutTemplate::class);

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
     * @param  WorkoutTemplate  $template  The template to use.
     * @param  CreateWorkoutFromTemplateAction  $createWorkout  Action to create the workout from the template.
     * @return RedirectResponse Redirects to the show page of the newly created workout.
     */
    public function execute(WorkoutTemplate $template, CreateWorkoutFromTemplateAction $createWorkout): RedirectResponse
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
     * @param  Workout  $workout  The workout to base the template on.
     * @param  CreateWorkoutTemplateFromWorkoutAction  $createTemplate  Action to create the template from the workout.
     * @return RedirectResponse Redirects to the templates index with a success message.
     */
    public function saveFromWorkout(Workout $workout, CreateWorkoutTemplateFromWorkoutAction $createTemplate): RedirectResponse
    {
        $this->authorize('view', $workout);

        $createTemplate->execute($this->user(), $workout);

        return redirect()->route('templates.index')->with('success', 'Modèle enregistré avec succès !');
    }

    /**
     * Remove the specified workout template from storage.
     *
     * @param  WorkoutTemplate  $template  The template to delete.
     * @return RedirectResponse Redirects back to the previous page.
     */
    public function destroy(WorkoutTemplate $template): RedirectResponse
    {
        $this->authorize('delete', $template);

        $template->delete();

        return back();
    }

    /**
     * Show a workout template (Not implemented in UI).
     */
    public function show(WorkoutTemplate $template): Response
    {
        abort(404);
    }

    /**
     * Edit a workout template (Not implemented in UI).
     */
    public function edit(WorkoutTemplate $template): \Illuminate\Http\Response
    {
        abort(404);
    }

    /**
     * Update a workout template (Not implemented in UI).
     */
    public function update(Request $request, WorkoutTemplate $template): \Illuminate\Http\Response
    {
        abort(404);
    }
}
