<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateWorkoutFromTemplateAction;
use App\Actions\CreateWorkoutTemplateAction;
use App\Actions\CreateWorkoutTemplateFromWorkoutAction;
use App\Models\Exercise;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Inertia\Inertia;

class WorkoutTemplateController extends Controller
{
    public function index(\App\Actions\FetchWorkoutTemplatesAction $fetchWorkoutTemplatesAction): \Inertia\Response
    {
        $this->authorize('viewAny', WorkoutTemplate::class);

        return Inertia::render('Workouts/Templates/Index', [
            'templates' => $fetchWorkoutTemplatesAction->execute($this->user()),
        ]);
    }

    public function create(): \Inertia\Response
    {
        $this->authorize('create', WorkoutTemplate::class);

        $idUtilisateur = $this->user()->id;

        return Inertia::render('Workouts/Templates/Create', [
            'exercises' => Exercise::enCachePourUtilisateur($idUtilisateur),
        ]);
    }

    public function store(\App\Http\Requests\StoreWorkoutTemplateRequest $request, CreateWorkoutTemplateAction $createWorkoutTemplateAction): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', WorkoutTemplate::class);

        /** @var array{name: string, description?: string|null, exercises?: array<int, array{id: int, sets?: array<int, array{reps?: int|null, weight?: float|null, is_warmup?: bool}>}>} $donneesValidees */
        $donneesValidees = $request->validated();
        $createWorkoutTemplateAction->execute($this->user(), $donneesValidees);

        return redirect()->route('templates.index');
    }

    /**
     * Ouvre une séance à partir du modèle et y envoie l'utilisateur.
     */
    public function execute(WorkoutTemplate $template, CreateWorkoutFromTemplateAction $createWorkout): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('view', $template);

        $workout = $createWorkout->execute($this->user(), $template);

        return redirect()->route('workouts.show', $workout);
    }

    /**
     * Enregistre une séance, terminée ou en cours, comme modèle réutilisable.
     */
    public function saveFromWorkout(Workout $workout, CreateWorkoutTemplateFromWorkoutAction $createTemplate): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('view', $workout);

        $createTemplate->execute($this->user(), $workout);

        return redirect()->route('templates.index')->with('success', 'Modèle enregistré avec succès !');
    }

    public function destroy(WorkoutTemplate $template): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $template);

        $template->delete();

        return back();
    }

    /**
     * Aucun écran ne montre un modèle seul : la route existe pour compléter la
     * ressource, elle répond 404.
     */
    public function show(WorkoutTemplate $template): \Inertia\Response
    {
        abort(404);
    }

    /**
     * Les lignes sont chargées avec leur exercice et leurs séries : c'est la
     * forme à partir de laquelle Templates/Edit construit son formulaire.
     */
    public function edit(WorkoutTemplate $template): \Inertia\Response
    {
        $this->authorize('update', $template);

        $template->load(['workoutTemplateLines.exercise', 'workoutTemplateLines.workoutTemplateSets']);

        return Inertia::render('Workouts/Templates/Edit', [
            'template' => $template,
            'exercises' => Exercise::enCachePourUtilisateur($this->user()->id),
        ]);
    }

    /**
     * Le travail revient à UpdateWorkoutTemplateAction, qui reconstruit les
     * lignes depuis l'état final soumis — la même action que le contrôleur API.
     */
    public function update(
        \App\Http\Requests\Api\WorkoutTemplateUpdateRequest $request,
        WorkoutTemplate $template,
        \App\Actions\UpdateWorkoutTemplateAction $updateWorkoutTemplateAction
    ): \Illuminate\Http\RedirectResponse {
        $this->authorize('update', $template);

        /** @var array{name: string, description?: string|null, exercises?: array<int, array{id: int, sets?: array<int, array{reps?: int|null, weight?: float|null, is_warmup?: bool}>}>} $donneesValidees */
        $donneesValidees = $request->validated();

        $updateWorkoutTemplateAction->execute($template, $donneesValidees);

        return redirect()->route('templates.index');
    }
}
