<?php

namespace App\Http\Controllers;

use App\Http\Requests\GoalStoreRequest;
use App\Models\Exercise;
use App\Models\Goal;
use App\Services\GoalService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class GoalController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected GoalService $goalService) {}

    public function index(): \Inertia\Response
    {
        return Inertia::render('Goals/Index', [
            'goals' => auth()->user()->goals()
                ->with('exercise')
                ->latest()
                ->get()
                ->append(['progress', 'unit']),
            'exercises' => Cache::remember('exercises_list_'.auth()->id(), 3600, function () {
                return Exercise::forUser(auth()->id())->orderBy('name')->get();
            }),
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
        $this->authorize('create', Goal::class);

        $data = $request->validated();
        if (! isset($data['start_value'])) {
            $data['start_value'] = 0;
        }

        $goal = new Goal;
        $goal->fill($data);
        $goal->user_id = auth()->id();
        $goal->save();

        $this->goalService->updateGoalProgress($goal);

        return redirect()->route('goals.index')->with('success', 'Objectif créé avec succès.');
    }

    public function update(GoalStoreRequest $request, Goal $goal): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $goal);

        $goal->update($request->validated());
        $this->goalService->updateGoalProgress($goal);

        return redirect()->route('goals.index')->with('success', 'Objectif mis à jour.');
    }

    public function destroy(Goal $goal): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $goal);

        $goal->delete();

        return redirect()->route('goals.index')->with('success', 'Objectif supprimé.');
    }
}
