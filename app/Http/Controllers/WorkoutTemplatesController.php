<?php

namespace App\Http\Controllers;

use App\Actions\CreateWorkoutFromTemplateAction;
use App\Actions\CreateWorkoutTemplateAction;
use App\Actions\CreateWorkoutTemplateFromWorkoutAction;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

/**
 * Controller for managing Workout Templates.
 *
 * This controller handles the lifecycle of workout templates, including:
 * - Listing, creating, and deleting templates.
 * - Instantiating a new active Workout from a Template.
 * - Saving an existing Workout as a new Template (snapshot).
 *
 * It delegates complex creation logic to dedicated Action classes to ensure
 * transactional integrity and cleaner controller methods.
 */
class WorkoutTemplatesController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the user's workout templates.
     *
     * Retrieves all templates belonging to the authenticated user,
     * eager loading nested relationships (lines, exercises, sets)
     * for efficient display in the UI.
     *
     * @return \Inertia\Response The Inertia response rendering the Templates index.
     */
    public function index(): \Inertia\Response
    {
        $this->authorize('viewAny', WorkoutTemplate::class);

        return Inertia::render('Workouts/Templates/Index', [
            'templates' => WorkoutTemplate::with(['workoutTemplateLines.exercise', 'workoutTemplateLines.workoutTemplateSets'])
                ->where('user_id', auth()->id())
                ->latest()
                ->get(),
        ]);
    }

    /**
     * Show the form for creating a new workout template.
     *
     * Prepares the list of available exercises (cached for performance)
     * to allow the user to build a template from scratch.
     *
     * @return \Inertia\Response The Inertia response rendering the Template creation form.
     */
    public function create(): \Inertia\Response
    {
        $this->authorize('create', WorkoutTemplate::class);

        return Inertia::render('Workouts/Templates/Create', [
            'exercises' => Cache::remember('exercises_list_'.auth()->id(), 3600, function () {
                return \App\Models\Exercise::forUser(auth()->id())
                    ->orderBy('name')
                    ->get();
            }),
        ]);
    }

    /**
     * Store a newly created workout template in storage.
     *
     * Delegates the actual creation logic to `CreateWorkoutTemplateAction`.
     *
     * @param  \App\Http\Requests\StoreWorkoutTemplateRequest  $request  Validated request containing template structure.
     * @param  \App\Actions\CreateWorkoutTemplateAction  $createWorkoutTemplateAction  Action to handle persistence.
     * @return \Illuminate\Http\RedirectResponse Redirects to the templates index upon success.
     */
    public function store(\App\Http\Requests\StoreWorkoutTemplateRequest $request, CreateWorkoutTemplateAction $createWorkoutTemplateAction): \Illuminate\Http\RedirectResponse
    {
        $createWorkoutTemplateAction->execute($request->user(), $request->validated());

        return redirect()->route('templates.index');
    }

    /**
     * Instantiate a new active Workout from a specific Template.
     *
     * This is the "Start Workout" action. It copies the template's structure
     * (exercises, sets, weights) into a new Workout session.
     *
     * @param  \App\Models\WorkoutTemplate  $template  The template to use.
     * @param  \App\Actions\CreateWorkoutFromTemplateAction  $createWorkout  Action to handle the cloning process.
     * @return \Illuminate\Http\RedirectResponse Redirects to the active workout view.
     */
    public function execute(WorkoutTemplate $template, CreateWorkoutFromTemplateAction $createWorkout): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('view', $template);

        $workout = $createWorkout->execute(auth()->user(), $template);

        return redirect()->route('workouts.show', $workout);
    }

    /**
     * Create a new Template based on an existing completed Workout.
     *
     * Useful for saving a successful session as a reusable routine.
     *
     * @param  \App\Models\Workout  $workout  The workout to snapshot.
     * @param  \App\Actions\CreateWorkoutTemplateFromWorkoutAction  $createTemplate  Action to handle the conversion.
     * @return \Illuminate\Http\RedirectResponse Redirects to the templates index.
     */
    public function saveFromWorkout(Workout $workout, CreateWorkoutTemplateFromWorkoutAction $createTemplate): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('view', $workout);

        $createTemplate->execute(auth()->user(), $workout);

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
