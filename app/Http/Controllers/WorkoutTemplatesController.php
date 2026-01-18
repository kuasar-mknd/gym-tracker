<?php

namespace App\Http\Controllers;

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
                ->where('user_id', auth()->id())
                ->latest()
                ->get(),
        ]);
    }

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

    public function store(\App\Http\Requests\StoreWorkoutTemplateRequest $request, CreateWorkoutTemplateAction $createWorkoutTemplateAction): \Illuminate\Http\RedirectResponse
    {
        $createWorkoutTemplateAction->execute($request->user(), $request->validated());

        return redirect()->route('templates.index');
    }

    public function execute(WorkoutTemplate $template): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('view', $template);

        $workout = new Workout([
            'name' => $template->name,
            'started_at' => now(),
        ]);
        $workout->user_id = auth()->id();
        $workout->save();

        foreach ($template->workoutTemplateLines as $templateLine) {
            $workoutLine = $workout->workoutLines()->create([
                'exercise_id' => $templateLine->exercise_id,
                'order' => $templateLine->order,
            ]);

            foreach ($templateLine->workoutTemplateSets as $templateSet) {
                $workoutLine->sets()->create([
                    'reps' => $templateSet->reps,
                    'weight' => $templateSet->weight,
                    'is_warmup' => $templateSet->is_warmup,
                ]);
            }
        }

        return redirect()->route('workouts.show', $workout);
    }

    public function saveFromWorkout(Workout $workout, CreateWorkoutTemplateFromWorkoutAction $createTemplate): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('view', $workout);

        $createTemplate->execute(auth()->user(), $workout);

        return redirect()->route('templates.index')->with('success', 'Modèle enregistré avec succès !');
    }

    public function destroy(WorkoutTemplate $template): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $template);

        $template->delete();

        return back();
    }
}
