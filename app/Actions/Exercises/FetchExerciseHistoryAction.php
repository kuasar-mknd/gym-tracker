<?php

declare(strict_types=1);

namespace App\Actions\Exercises;

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutLine;
use App\Traits\CalculatesOneRepMax;
use Illuminate\Support\Collection;

class FetchExerciseHistoryAction
{
    use CalculatesOneRepMax;

    /**
     * @return \Illuminate\Support\Collection<int, array{
     *     id: int,
     *     workout_id: int,
     *     workout_name: string,
     *     formatted_date: string,
     *     best_1rm: float,
     *     sets: \Illuminate\Support\Collection<int, array<string, mixed>>
     * }>
     */
    public function execute(User $user, Exercise $exercise): Collection
    {
        // ⚡ Bolt: PERFORMANCE OPTIMIZATION
        // Replaced whereHas('workout') subquery with a direct INNER JOIN to the workouts table
        // to avoid an expensive EXISTS subquery execution, significantly improving performance for users with large workout histories.
        // @phpstan-ignore-next-line
        return WorkoutLine::query()
            ->select('workout_lines.*')
            ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
            ->where('workout_lines.exercise_id', $exercise->id)
            ->where('workouts.user_id', $user->id)
            ->whereNotNull('workouts.started_at')
            // La meme fenetre que `getExercise1RMProgress`, appelee juste
            // au-dessus dans `ExerciseController::show`. L'historique complet
            // partait entier dans la prop Inertia : trois ans de developpe
            // couche font ~1 200 series hydratees et serialisees a chaque
            // ouverture de fiche.
            ->where('workouts.started_at', '>=', now()->subDays(365))
            ->with(['workout', 'sets'])
            ->get()
            /*
             * La garde qui etait ici — `! $workout || ! $workout->started_at` —
             * ne pouvait pas se declencher : la requete fait une jointure
             * INTERNE sur `workouts` et ecarte deja les dates nulles. Elle en
             * soutenait une seconde, le `->filter()` qui suivait le map et ne
             * retirait jamais rien.
             */
            ->map(function (WorkoutLine $line): array {
                $workout = $line->workout;

                $sets = $line->sets->map(fn ($set): array => [
                    'weight' => (float) $set->weight,
                    'reps' => (int) $set->reps,
                    'one_rep_max' => $this->calculate1RM((float) $set->weight, (int) $set->reps),
                ]);

                $best1rm = $sets->max('one_rep_max') ?? 0.0;

                return [
                    'id' => $line->id,
                    'workout_id' => $workout->id,
                    'workout_name' => $workout->name,
                    'formatted_date' => $workout->started_at?->format('d/m'),
                    'best_1rm' => $best1rm,
                    'sets' => $sets,
                    'started_at' => $workout->started_at, // For sorting
                ];
            })
            ->sortByDesc('started_at')
            ->values()
            ->map(function (array $item): array {
                unset($item['started_at']);

                return $item;
            });
    }
}
