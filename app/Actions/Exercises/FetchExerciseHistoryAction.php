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
     * `best_1rm` vaut null quand aucune serie ne peut etablir le record : une
     * seance lancee depuis un modele puis abandonnee, ou faite seulement
     * d'echauffements, n'a pas de 1RM, et les graphiques n'y tracent pas de
     * point plutot qu'une chute a zero.
     *
     * @return \Illuminate\Support\Collection<int, array{
     *     id: int,
     *     workout_id: int,
     *     workout_name: string,
     *     formatted_date: string,
     *     best_1rm: float|null,
     *     sets: \Illuminate\Support\Collection<int, array{weight: float, reps: int, one_rep_max: float, is_completed: bool, is_warmup: bool}>
     * }>
     */
    public function execute(User $user, Exercise $exercise): Collection
    {
        // Jointure INTERNE sur `workouts` plutôt que `whereHas('workout')` : la
        // sous-requête EXISTS coûtait cher dès qu'un utilisateur avait un long
        // historique.
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

                $sets = $line->sets->map(fn (Set $set): array => [
                    'weight' => (float) $set->weight,
                    'reps' => (int) $set->reps,
                    'one_rep_max' => $this->calculate1RM((float) $set->weight, (int) $set->reps),
                    'is_completed' => $set->is_completed,
                    'is_warmup' => $set->is_warmup,
                ]);

                /*
                 * La liste reste complete, le meilleur 1RM non : il ne lit que
                 * les series qui peuvent etablir le record (#1956), et reste
                 * nul quand aucune ne le peut.
                 */
                $best1rm = $line->sets
                    ->filter(fn (Set $set): bool => self::peutEtablirLeRecord($set))
                    ->map(fn (Set $set): float => $this->calculate1RM((float) $set->weight, (int) $set->reps))
                    ->max();

                return [
                    'id' => $line->id,
                    'workout_id' => $workout->id,
                    'workout_name' => $workout->name,
                    'formatted_date' => $workout->started_at?->format('d/m'),
                    'best_1rm' => $best1rm,
                    'sets' => $sets,
                    'started_at' => $workout->started_at, // Pour le tri, retirée juste après.
                ];
            })
            ->sortByDesc('started_at')
            ->values()
            ->map(function (array $item): array {
                unset($item['started_at']);

                return $item;
            });
    }

    /**
     * La serie compte dans le meilleur 1RM de la seance.
     *
     * Les memes conditions que le record « 1RM estime »
     * (`PersonalRecordService`) : validee, hors echauffement, avec un poids et
     * des repetitions. Le meilleur 1RM lisait toutes les series, et une serie
     * prevue a 140 kg puis jamais faite le portait au-dessus du record.
     */
    private static function peutEtablirLeRecord(Set $set): bool
    {
        return $set->is_completed
            && ! $set->is_warmup
            && $set->weight !== null && $set->weight > 0.0
            && $set->reps !== null && $set->reps > 0;
    }
}
