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
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si la séance référencée n'existe pas.
     * @throws \Illuminate\Auth\Access\AuthorizationException Si l'utilisateur n'a pas le droit d'ajouter une ligne à cette séance.
     */
    public function store(WorkoutLineStoreRequest $request, CreateWorkoutLineAction $action): WorkoutLineResource
    {
        $validated = $request->validated();

        /** @var \App\Models\Workout $workout */
        $workout = Workout::findOrFail($validated['workout_id']);

        $this->authorize('create', [WorkoutLine::class, $workout]);

        // Transmise dans un en-tête plutôt que dans le corps : elle nomme la
        // tentative, pas la ressource, et n'a rien à faire dans le payload validé.
        $validated['idempotency_key'] = $request->header('Idempotency-Key');

        $workoutLine = $action->execute($workout, $validated);

        $workoutLine->load(['exercise', 'sets']);

        // L'accesseur lit le cache tout seul : l'ajouter ici ne coûte pas de requête.
        $workoutLine->append('recommended_values');

        return new WorkoutLineResource($workoutLine);
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException Si l'utilisateur n'a pas le droit de supprimer la ligne d'exercice.
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
