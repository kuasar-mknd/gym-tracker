<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

/**
 * De quoi afficher une séance.
 *
 * Toutes les relations imbriquées que la page lit — lignes, séries, exercices,
 * records personnels — et la bibliothèque d'exercices où puiser pour en ajouter.
 */
class FetchWorkoutShowAction
{
    /**
     * Chaque prop est une fermeture, qu'Inertia ne résout que si elle part dans
     * la réponse : le rechargement de la seule séance après le vidage de la
     * file (`router.reload({ only: ['workout'] })`) relisait sinon la
     * bibliothèque d'exercices pour la jeter aussitôt (#2148, étape 3a).
     *
     * @return array{
     *     workout: \Closure(): \App\Models\Workout,
     *     exercises: \Closure(): \Illuminate\Database\Eloquent\Collection<int, \App\Models\Exercise>
     * }
     */
    public function execute(User $user, Workout $workout): array
    {
        return [
            'workout' => function () use ($user, $workout): Workout {
                $workout->load(['workoutLines.exercise', 'workoutLines.sets.personalRecord']);

                // Les valeurs recommandées sont chargées en bloc, une ou deux
                // requêtes, et non une par ligne, avant que la séance parte
                // dans la réponse. La séance, déjà chargée par la route, n'est
                // pas relue (#2148, étape 3c).
                WorkoutLine::batchRecommendedValues($workout->workoutLines, $user->id, $workout);

                return $workout;
            },
            'exercises' => fn (): \Illuminate\Database\Eloquent\Collection => Exercise::enCachePourUtilisateur($user->id),
        ];
    }
}
