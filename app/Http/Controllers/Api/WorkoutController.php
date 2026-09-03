<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\WorkoutResource;
use App\Models\Workout;

/**
 * Réordonner les exercices d'une séance : la seule écriture de séance que
 * la page fait en direct, le reste passe par les routes web.
 */
class WorkoutController extends Controller
{
    public function reorderLines(
        \App\Http\Requests\Api\WorkoutLineOrderRequest $request,
        Workout $workout,
        \App\Actions\Workouts\ReorderWorkoutLinesAction $reordonner
    ): WorkoutResource {
        $this->authorize('update', $workout);

        /** @var list<int> $lignes */
        $lignes = array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, (array) $request->validated('lines'));

        /*
         * L'ordre soumis doit etre une PERMUTATION des lignes de la seance : une
         * liste partielle laisserait les absentes sur leur rang d'origine, donc
         * en double avec celles qu'on vient de renumeroter.
         *
         * Verifie ICI, apres l'autorisation, et non dans la requete de
         * validation : une lecture faite avant elle couterait une requete de
         * plus pour une seance qui existe mais n'appartient pas a l'appelant
         * que pour une seance inconnue. Le contrat de non-divulgation compte
         * ces requetes.
         */
        $attendues = $workout->workoutLines()->pluck('id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        $soumises = $lignes;
        sort($soumises);
        sort($attendues);

        if ($soumises !== $attendues) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'lines' => 'La liste doit contenir exactement les exercices de la séance, une fois chacun.',
            ]);
        }

        $reordonner->execute($workout, $lignes);

        return new WorkoutResource($workout->load(['workoutLines.exercise', 'workoutLines.sets']));
    }
}
