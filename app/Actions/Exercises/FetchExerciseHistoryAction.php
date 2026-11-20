<?php

declare(strict_types=1);

namespace App\Actions\Exercises;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\WorkoutLine;
use App\Traits\CalculatesOneRepMax;
use Illuminate\Support\Collection;

class FetchExerciseHistoryAction
{
    use CalculatesOneRepMax;

    /**
     * L'historique d'un exercice sur un an, la seance la plus recente en tete.
     *
     * Chaque serie porte `is_completed` et `is_warmup` : la page d'exercice
     * tire ses graphiques de cette liste, et ils ne comptent que les series
     * validees, comme le volume de la seance et les records (#1956). La liste
     * elle-meme reste complete.
     *
     * @return \Illuminate\Support\Collection<int, array{
     *     id: int,
     *     workout_id: int,
     *     workout_name: string|null,
     *     formatted_date: string,
     *     sets: \Illuminate\Support\Collection<int, array{weight: float, reps: int, one_rep_max: float, is_completed: bool, is_warmup: bool}>
     * }>
     */
    public function execute(User $user, Exercise $exercise): Collection
    {
        /*
         * Les colonnes dénormalisées de la ligne (`user_id`,
         * `workout_started_at`), sans jointure sur `workouts` : le filtre et
         * l'ordre tiennent dans l'index `(user_id, exercise_id,
         * workout_started_at)`. Une séance sans début a une date nulle, que
         * `>=` écarte. À date égale, la clef primaire croissante : l'ordre
         * est désormais déterministe. L'ancienne jointure entrait par
         * `workouts_user_id_started_at_index`, et l'ordre des égalités venait
         * de l'id de séance, que rien ne garantissait.
         * La même fenêtre que `getExercise1RMProgress`.
         */
        return WorkoutLine::query()
            ->where('user_id', $user->id)
            ->where('exercise_id', $exercise->id)
            ->where('workout_started_at', '>=', now()->subDays(365))
            ->orderByDesc('workout_started_at')
            ->orderBy('id')
            ->with(['workout', 'sets'])
            ->get()
            ->map(function (WorkoutLine $line): array {
                $workout = $line->workout;

                $sets = $line->sets->map(fn (Set $set): array => [
                    'weight' => (float) $set->weight,
                    'reps' => (int) $set->reps,
                    'one_rep_max' => $this->calculate1RM((float) $set->weight, (int) $set->reps),
                    'is_completed' => $set->is_completed,
                    'is_warmup' => $set->is_warmup,
                ]);

                return [
                    'id' => $line->id,
                    'workout_id' => $workout->id,
                    'workout_name' => $workout->name,
                    'formatted_date' => $workout->started_at?->format('d/m'),
                    'sets' => $sets,
                ];
            });
    }
}
