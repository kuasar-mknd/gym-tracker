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

class WorkoutTemplatesController extends Controller
{
    use AuthorizesRequests;

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

    public function create(): \Inertia\Response
    {
        $this->authorize('create', WorkoutTemplate::class);

        $userId = $this->user()->id;

        return Inertia::render('Workouts/Templates/Create', [
            'exercises' => Cache::remember('exercises_list_'.$userId, 3600, fn () => \App\Models\Exercise::forUser($userId)
                ->orderBy('name')
                ->get()),
        ]);
    }

    public function store(\App\Http\Requests\StoreWorkoutTemplateRequest $request, CreateWorkoutTemplateAction $createWorkoutTemplateAction): \Illuminate\Http\RedirectResponse
    {
        /** @var array{name: string, description?: string|null, exercises?: array<int, array{id: int, sets?: array<int, array{reps?: int|null, weight?: float|null, is_warmup?: bool}>}>} $validated */
        $validated = $request->validated();
        $createWorkoutTemplateAction->execute($this->user(), $validated);

        return redirect()->route('templates.index');
    }

    public function execute(WorkoutTemplate $template, CreateWorkoutFromTemplateAction $createWorkout): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('view', $template);

        $workout = $createWorkout->execute($this->user(), $template);

        return redirect()->route('workouts.show', $workout);
    }

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
}
