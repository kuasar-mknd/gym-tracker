<?php

namespace App\Http\Controllers;

use App\Http\Requests\GoalStoreRequest;
use App\Models\Exercise;
use App\Models\Goal;
use App\Services\GoalService;
use Inertia\Inertia;

class GoalController extends Controller
{
    public function __construct(protected GoalService $goalService) {}

    public function index(): \Inertia\Response
    {
        return Inertia::render('Goals/Index', [
            'goals' => auth()->user()->goals()
                ->with('exercise')
                ->latest()
                ->get()
                ->append(['progress', 'unit']),
            'exercises' => Exercise::orderBy('name')->get(),
            'measurementTypes' => [
                ['value' => 'weight', 'label' => 'Poids de corps'],
                ['value' => 'waist', 'label' => 'Tour de taille'],
                ['value' => 'body_fat', 'label' => 'Masse grasse (%)'],
                ['value' => 'chest', 'label' => 'Tour de poitrine'],
                ['value' => 'arms', 'label' => 'Tour de bras'],
            ],
        ]);
    }

    public function store(GoalStoreRequest $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validated();
        $data['user_id'] = auth()->id();

        // If start_value is not provided, try to find a current value
        if (! isset($data['start_value'])) {
            // We'll let GoalService handle the first sync which will populate current_value
            // but it's good to have a start_value for progress calculation.
            $data['start_value'] = 0;
        }

        $goal = Goal::create($data);

        // Initial sync to get current progress
        $this->goalService->updateGoalProgress($goal);

        return redirect()->route('goals.index')->with('success', 'Objectif créé avec succès.');
    }

    public function update(GoalStoreRequest $request, Goal $goal): \Illuminate\Http\RedirectResponse
    {
        abort_if($goal->user_id !== auth()->id(), 403);

        $goal->update($request->validated());
        $this->goalService->updateGoalProgress($goal);

        return redirect()->route('goals.index')->with('success', 'Objectif mis à jour.');
    }

    public function destroy(Goal $goal): \Illuminate\Http\RedirectResponse
    {
        abort_if($goal->user_id !== auth()->id(), 403);

        $goal->delete();

        return redirect()->route('goals.index')->with('success', 'Objectif supprimé.');
    }
}
