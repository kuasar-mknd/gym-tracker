<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Goals\CreateGoalAction;
use App\Http\Requests\GoalStoreRequest;
use App\Models\Exercise;
use App\Models\Goal;
use App\Services\GoalService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Inertia\Inertia;

/**
 * Controller for managing user goals.
 *
 * This controller handles the creation, retrieval, updating, and deletion
 * of user fitness and measurement goals. It interfaces with the GoalService
 * to recalculate goal progress whenever a goal is updated.
 */
class GoalController extends Controller
{
    use AuthorizesRequests;

    /**
     * Create a new GoalController instance.
     *
     * @param  \App\Services\GoalService  $goalService  The service responsible for updating goal progress.
     */
    public function __construct(protected GoalService $goalService)
    {
    }

    /**
     * Display a listing of the user's goals.
     *
     * Retrieves all goals for the authenticated user, along with available
     * exercises and predefined measurement types for the creation form.
     * Eager loads the associated exercise for each goal.
     *
     * @return \Inertia\Response The Inertia response rendering the 'Goals/Index' page.
     */
    public function index(): \Inertia\Response
    {
        return Inertia::render('Goals/Index', [
            'goals' => $this->user()->goals()
                ->with('exercise')
                ->latest()
                ->get()
                ->append(['progress', 'unit']),
            'exercises' => Exercise::getCachedForUser($this->user()->id),
            'measurementTypes' => [
                ['value' => 'weight', 'label' => 'Poids de corps'],
                ['value' => 'waist', 'label' => 'Tour de taille'],
                ['value' => 'body_fat', 'label' => 'Masse grasse (%)'],
                ['value' => 'chest', 'label' => 'Tour de poitrine'],
                ['value' => 'arms', 'label' => 'Tour de bras'],
            ],
        ]);
    }

    /**
     * Store a newly created goal in storage.
     *
     * Validates the request data, sets a default start value if none is provided,
     * creates the goal, and immediately calculates its initial progress.
     *
     * @param  \App\Http\Requests\GoalStoreRequest  $request  The validated request containing goal details.
     * @return \Illuminate\Http\RedirectResponse A redirect back to the goals index.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to create a goal.
     */
    public function store(GoalStoreRequest $request, CreateGoalAction $createGoalAction): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', Goal::class);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $createGoalAction->execute($this->user(), $data);

        return redirect()->route('goals.index')->with('success', 'Objectif créé avec succès.');
    }

    /**
     * Update the specified goal in storage.
     *
     * Validates the incoming data, updates the goal's attributes, and recalculates
     * its progress to reflect the new target or criteria.
     *
     * @param  \App\Http\Requests\GoalStoreRequest  $request  The validated request containing updated goal details.
     * @param  \App\Models\Goal  $goal  The goal instance to update.
     * @return \Illuminate\Http\RedirectResponse A redirect back to the goals index.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update the goal.
     */
    public function update(GoalStoreRequest $request, Goal $goal): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $goal);

        $goal->update($request->validated());
        $this->goalService->updateGoalProgress($goal);

        return redirect()->route('goals.index')->with('success', 'Objectif mis à jour.');
    }

    /**
     * Remove the specified goal from storage.
     *
     * Permanently deletes the given goal from the database.
     *
     * @param  \App\Models\Goal  $goal  The goal instance to delete.
     * @return \Illuminate\Http\RedirectResponse A redirect back to the goals index.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete the goal.
     */
    public function destroy(Goal $goal): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $goal);

        $goal->delete();

        return redirect()->route('goals.index')->with('success', 'Objectif supprimé.');
    }
}
