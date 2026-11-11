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
     * @return array{
     *     workout: \App\Models\Workout,
     *     exercises: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Exercise>|array<int, \App\Models\Exercise>
     * }
     */
    public function execute(User $user, Workout $workout): array
    {
        $exercises = Exercise::enCachePourUtilisateur($user->id);

        $workout->load(['workoutLines.exercise', 'workoutLines.sets.personalRecord']);

        // Les valeurs recommandées sont chargées en bloc, une ou deux requêtes,
        // et non une par ligne. La séance, déjà chargée par la route, n'est
        // pas relue (#2148, étape 3c).
        WorkoutLine::batchRecommendedValues($workout->workoutLines, $user->id, $workout);

        return [
            'workout' => $workout,
            'exercises' => $exercises,
        ];
    }
}
