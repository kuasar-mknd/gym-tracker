<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\DTOs\Stats\Exercise1RMProgressPoint;
use App\DTOs\Stats\MuscleDistributionStat;
use App\Models\Set;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class ExerciseStatsService
{
    /**
     * @return array<int, MuscleDistributionStat>
     */
    public function getMuscleDistribution(User $user, int $days = 30): array
    {
        return Cache::remember(
            ClesDeStats::seances($user, "muscle_dist.{$days}"),
            now()->addMinutes(30),
            fn (): array => $this->repartitionParCategorie($user, $days),
        );
    }

    /**
     * Le volume de la fenetre, reparti par categorie d'exercice.
     *
     * `exercises` ne figure PAS dans l'agregation. Joint et groupe par
     * `exercises.category`, MySQL attaquait par le catalogue — son index
     * `(user_id, category, name)` rendait le groupe deja trie, ce qu'il prefere
     * a tout le reste — puis tirait toutes les lignes de chaque exercice, tous
     * utilisateurs confondus. La borne de trente jours ne s'appliquait qu'a la
     * fin, ligne par ligne : elle etait decorative, 179 lectures d'index a 40
     * seances contre 581 a 200 pour une fenetre qui en contient trente dans les
     * deux cas.
     *
     * Agregees par `exercise_id`, les lignes sont lues par `(user_id,
     * workout_started_at)` et rien ne detourne le plan. Les categories sont
     * relues a part, par clef primaire, sur les seuls exercices rencontres.
     *
     * @return array<int, MuscleDistributionStat>
     */
    private function repartitionParCategorie(User $user, int $days): array
    {
        $parExercice = Set::query()
            ->toBase()
            ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
            ->where('workout_lines.user_id', $user->id)
            ->where('workout_lines.workout_started_at', '>=', now()->subDays($days))
            ->selectRaw('workout_lines.exercise_id, SUM(sets.weight * sets.reps) as volume')
            ->groupBy('workout_lines.exercise_id')
            ->get();

        /** @var \Illuminate\Support\Collection<int, string|null> $categories */
        $categories = \Illuminate\Support\Facades\DB::table('exercises')
            ->whereIn('id', $parExercice->pluck('exercise_id')->all())
            ->pluck('category', 'id');

        $volumes = [];

        foreach ($parExercice as $ligne) {
            $exerciceId = is_numeric($ligne->exercise_id) ? (int) $ligne->exercise_id : 0;
            $categorie = $categories[$exerciceId] ?? null;
            $cle = is_string($categorie) ? $categorie : 'Unknown';

            // `SUM()` rend NULL quand tout le groupe l'est : `sets.weight` et
            // `sets.reps` sont nullables.
            $volumes[$cle] = ($volumes[$cle] ?? 0.0) + (is_numeric($ligne->volume) ? (float) $ligne->volume : 0.0);
        }

        $repartition = [];

        foreach ($volumes as $categorie => $volume) {
            $repartition[] = new MuscleDistributionStat($categorie, $volume);
        }

        return $repartition;
    }

    /**
     * @return array<int, Exercise1RMProgressPoint>
     */
    public function getExercise1RMProgress(User $user, int $exerciseId, int $days = 90): array
    {
        return Cache::remember(
            ClesDeStats::seances($user, "1rm.{$exerciseId}.{$days}"),
            now()->addMinutes(30),
            fn (): array => Set::query()
                ->toBase()
                ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
                ->where('workout_lines.user_id', $user->id)
                ->where('workout_lines.exercise_id', $exerciseId)
                ->where('workout_lines.workout_started_at', '>=', now()->subDays($days))
                ->selectRaw('workout_lines.workout_started_at as started_at, MAX(sets.weight * (1 + sets.reps / 30.0)) as epley_1rm')
                ->groupBy('workout_lines.workout_started_at')
                ->orderBy('workout_lines.workout_started_at')
                ->get()
                ->map(function (\stdClass $set): Exercise1RMProgressPoint {
                    /*
                     * `workouts.started_at` est NOT NULL : `strtotime()` ne
                     * pouvait pas rendre false, et les deux replis sur « ?? »
                     * n'etaient jamais atteints — six mutants y survivaient.
                     *
                     * Ils meritaient d'autant moins de rester qu'ils produisaient
                     * une etiquette « ?? » sur la courbe plutot que d'echouer :
                     * un point de graphique nomme « ?? » n'aide personne, et
                     * n'arrivait de toute facon jamais.
                     *
                     * Quatrieme fois que ce motif est retire (#1459, #1474, #1493).
                     */
                    /*
                     * `toBase()` court-circuite Eloquent : `get()` rend des
                     * `stdClass` dont chaque propriete est `mixed`, et le cast
                     * portait donc sur `mixed` (#1482). Dire le type de la
                     * colonne vaut mieux que caster ce qu'on ne connait pas —
                     * MySQL rend un `timestamp` en chaine, et aucun cast de
                     * modele ne s'applique hors d'Eloquent.
                     */
                    /** @var string $demarreLe */
                    $demarreLe = $set->started_at;

                    $jour = CarbonImmutable::parse($demarreLe);

                    return new Exercise1RMProgressPoint(
                        $jour->format('d/m'),
                        $jour->format('Y-m-d'),
                        is_numeric($set->epley_1rm) ? (float) $set->epley_1rm : 0.0,
                    );
                })
                ->all()
        );
    }
}
