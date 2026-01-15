<?php

namespace App\Http\Controllers;

use App\Actions\CreateWorkoutTemplateAction;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WorkoutTemplatesController extends Controller
{
    public function index(): \Inertia\Response
    {
        return Inertia::render('Workouts/Templates/Index', [
            'templates' => WorkoutTemplate::with(['workoutTemplateLines.exercise', 'workoutTemplateLines.workoutTemplateSets'])
                ->where('user_id', auth()->id())
                ->latest()
                ->get(),
        ]);
    }

    public function create(): \Inertia\Response
    {
        return Inertia::render('Workouts/Templates/Create', [
            'exercises' => \App\Models\Exercise::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, CreateWorkoutTemplateAction $createWorkoutTemplateAction): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'exercises' => 'nullable|array',
        ]);

        $createWorkoutTemplateAction->execute($request->user(), $validated);

        return redirect()->route('templates.index');
    }

    public function execute(WorkoutTemplate $template): \Illuminate\Http\RedirectResponse
    {
        abort_if($template->user_id !== auth()->id(), 403);

        $workout = Workout::create([
            'user_id' => auth()->id(),
            'name' => $template->name,
            'started_at' => now(),
        ]);

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

    public function saveFromWorkout(Workout $workout): \Illuminate\Http\RedirectResponse
    {
        abort_if($workout->user_id !== auth()->id(), 403);

        $template = WorkoutTemplate::create([
            'user_id' => auth()->id(),
            'name' => $workout->name.' (Modèle)',
            'description' => 'Créé à partir de la séance du '.$workout->created_at->format('d/m/Y'),
        ]);

        foreach ($workout->workoutLines as $line) {
            $templateLine = $template->workoutTemplateLines()->create([
                'exercise_id' => $line->exercise_id,
                'order' => $line->order,
            ]);

            foreach ($line->sets as $set) {
                $templateLine->workoutTemplateSets()->create([
                    'reps' => $set->reps,
                    'weight' => $set->weight,
                    'is_warmup' => $set->is_warmup,
                    'order' => $set->id, // Simple order for now
                ]);
            }
        }

        return redirect()->route('templates.index')->with('success', 'Modèle enregistré avec succès !');
    }

    public function destroy(WorkoutTemplate $template): \Illuminate\Http\RedirectResponse
    {
        abort_if($template->user_id !== auth()->id(), 403);

        $template->delete();

        return back();
    }
}
