<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Workouts\CreateWorkoutLineAction;
use App\Http\Requests\Api\WorkoutLineStoreRequest;
use App\Http\Resources\WorkoutLineResource;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Http\Response;

/**
 * Ajouter, retirer et réordonner les exercices d'une séance en cours.
 */
class WorkoutLineController extends Controller
{
    /**
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the referenced workout does not exist.
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to add a line to the workout.
     */
    public function store(WorkoutLineStoreRequest $request, CreateWorkoutLineAction $action): WorkoutLineResource
    {
        $validated = $request->validated();

        /** @var \App\Models\Workout $workout */
        $workout = Workout::findOrFail($validated['workout_id']);

        $this->authorize('create', [WorkoutLine::class, $workout]);

        // Carried in a header rather than the body: it names the attempt, not
        // the resource, and has no business in the validated payload.
        $validated['idempotency_key'] = $request->header('Idempotency-Key');

        $workoutLine = $action->execute($workout, $validated);

        $workoutLine->load(['exercise', 'sets']);

        // ⚡ Perf: Accessor uses cache automatically
        $workoutLine->append('recommended_values');

        return new WorkoutLineResource($workoutLine);
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete the workout line.
     */
    public function destroy(WorkoutLine $workoutLine): Response
    {
        $this->authorize('delete', $workoutLine);

        $user = $workoutLine->workout?->user;

        $workoutLine->delete();

        /*
         * Retirer un exercice change le volume de la seance, et rien ne le
         * disait au cache : les chiffres affiches restaient faux jusqu'a une
         * demi-heure. Supprimer une SERIE l'invalidait bien, supprimer une
         * SEANCE aussi — la ligne, entre les deux, avait ete oubliee (#1502).
         */
        if ($user !== null) {
            app(\App\Services\Stats\StatsCacheManager::class)->clearWorkoutRelatedStats($user);
        }

        return response()->noContent();
    }

    /**
     * Renumerote les series d'un exercice depuis l'ordre soumis.
     *
     * Un point d'entree a part plutot qu'un champ de plus sur `update` : la
     * page de seance ecrit par petites touches — une valeur, une validation —
     * et renvoyer la ligne entiere pour deplacer une serie se battrait avec
     * l'ordonnancement optimiste qui tient ces ecritures.
     */
    public function reorderSets(
        \App\Http\Requests\Api\SetOrderRequest $request,
        WorkoutLine $workoutLine,
        \App\Actions\Workouts\ReorderAction $reordonner
    ): WorkoutLineResource {
        $this->authorize('update', $workoutLine);

        $reordonner->execute($workoutLine->sets(), (array) $request->validated('sets'), 'sets');

        return new WorkoutLineResource($workoutLine->load(['exercise', 'sets']));
    }
}
