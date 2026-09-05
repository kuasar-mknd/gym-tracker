<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Set;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Service for calculating and managing recommended workout values.
 *
 * This service determines the optimal weight, reps, distance, and duration
 * for a specific exercise within a workout line, based on the user's past
 * performance data. It uses caching to improve performance for frequently
 * requested recommendations.
 */
final class RecommendedValuesService
{
    /**
     * Nombre de lignes precedentes examinees par exercice avant de renoncer.
     *
     * Une ligne dont toutes les series sont restees au pre-remplissage de
     * l'ecran ne porte aucune information ; on remonte alors a la ligne
     * d'avant, jusqu'a cette profondeur. Voir #1677.
     */
    private const int PROFONDEUR = 5;

    /**
     * Clef par utilisateur, exercice et séance, portant une version : une
     * série enregistrée incrémente la version et rend obsolètes d'un coup
     * toutes les recommandations de l'utilisateur, sans les énumérer.
     */
    public static function cleDeCache(int $userId, int $exerciseId, int $workoutId): string
    {
        $version = Cache::get("recommended_values:version:{$userId}", 0);

        return "recommended_values:{$userId}:v".(is_numeric($version) ? (int) $version : 0).":{$exerciseId}:{$workoutId}";
    }

    public function invaliderPour(int $userId): void
    {
        Cache::increment("recommended_values:version:{$userId}");
    }

    /**
     * Get the recommended values for a given workout line.
     *
     * Analyzes previous workout history for the same exercise and user to
     * suggest the most likely weight, repetitions, distance, and duration.
     * Caches the result to minimize database queries.
     *
     * @param  WorkoutLine  $line  The workout line requiring recommended values.
     * @return array{weight: float, reps: int, distance_km: float, duration_seconds: int}
     */
    public function getRecommendedValues(WorkoutLine $line): array
    {
        $line->loadMissing('workout');
        $workout = $line->workout;

        if ($workout === null) {
            return $this->getDefaultValues();
        }

        $cacheKey = self::cleDeCache((int) $workout->user_id, (int) $line->exercise_id, (int) $line->workout_id);

        /** @var array{weight: float, reps: int, distance_km: float, duration_seconds: int}|null $cached */
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        /*
         * Sans jointure : le filtre et l'ordre portent desormais sur la meme
         * table, donc `workout_lines(user_id, exercise_id, workout_started_at)`
         * les sert tous les deux et le `LIMIT` s'arrete aux premieres lignes.
         *
         * La jointure d'avant filtrait sur `workout_lines` et ordonnait sur
         * `workouts` : aucun index ne pouvant servir les deux, MySQL
         * materialisait toute la jointure et la triait. Mesure sur un exercice
         * present dans chaque seance : 601 lignes lues a 600 seances.
         */
        $candidates = WorkoutLine::query()
            ->with(['sets'])
            ->where('user_id', $workout->user_id)
            ->where('exercise_id', $line->exercise_id)
            ->where('workout_started_at', '<', $workout->started_at)
            ->where('workout_id', '!=', $workout->id)
            ->orderByDesc('workout_started_at')
            ->orderByDesc('id')
            ->limit(self::PROFONDEUR)
            ->get();

        $values = $this->calculateFromLines($candidates);
        Cache::put($cacheKey, $values, 300);

        return $values;
    }

    /**
     * Batch-load recommended values for a collection of workout lines.
     *
     * Efficiently resolves recommended values for multiple workout lines
     * simultaneously, utilizing caching and minimizing database lookups.
     * Automatically applies these resolved values back onto the provided models.
     *
     * @param  Collection<int, WorkoutLine>  $lines  A collection of workout lines to populate.
     * @param  int  $userId  The ID of the user the lines belong to.
     * @return array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}> A dictionary mapping exercise IDs to their recommended values.
     */
    public function batchRecommendedValues(Collection $lines, int $userId): array
    {
        if ($lines->isEmpty()) {
            return [];
        }

        $workoutId = $lines->first()->workout_id;
        /** @var array<int, int> $exerciseIds */
        $exerciseIds = $lines->pluck('exercise_id')->unique()->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->values()->all();

        $workout = $this->resolveWorkout($workoutId, $exerciseIds);
        if ($workout === null) {
            return [];
        }

        $defaults = $this->getDefaultValues();
        $results = $this->getResultsFromCacheOrFetch($exerciseIds, $userId, (int) $workoutId, $workout);

        $this->applyRecommendedValuesToLines($lines, $results, $defaults);

        return $results;
    }

    /**
     * Resolve the Workout model associated with the given ID.
     *
     * Ensures that the workout ID is valid and that there are related
     * exercise IDs before attempting to retrieve the model.
     *
     * @param  int|null  $workoutId  The ID of the workout to resolve.
     * @param  array<int, int>  $exerciseIds  The array of associated exercise IDs.
     * @return Workout|null The resolved Workout model, or null if invalid or missing.
     */
    private function resolveWorkout(?int $workoutId, array $exerciseIds): ?Workout
    {
        if ($workoutId === null || count($exerciseIds) === 0) {
            return null;
        }

        return Workout::find($workoutId);
    }

    /**
     * Apply calculated recommended values directly to the WorkoutLine models.
     *
     * Sets a non-persisted 'recommended_values' attribute on each line,
     * falling back to default values if no recommendation is found.
     *
     * @param  Collection<int, WorkoutLine>  $lines  The lines to update.
     * @param  array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}>  $results  The calculated results keyed by exercise ID.
     * @param  array{weight: float, reps: int, distance_km: float, duration_seconds: int}  $defaults  The default fallback values.
     */
    private function applyRecommendedValuesToLines(Collection $lines, array $results, array $defaults): void
    {
        foreach ($lines as $line) {
            $line->setRecommendedValuesAttribute($results[$line->exercise_id] ?? $defaults);
        }
    }

    /**
     * Get the default baseline values for a workout line.
     *
     * @return array{weight: float, reps: int, distance_km: float, duration_seconds: int} Default set parameters.
     */
    private function getDefaultValues(): array
    {
        return [
            'weight' => 0.0,
            'reps' => 10,
            'distance_km' => 0.0,
            'duration_seconds' => 30,
        ];
    }

    /**
     * Une serie laissee au pre-remplissage de l'ecran ne dit rien de l'exercice.
     *
     * L'ecran ajoute chaque serie avec 0 kg, 10 repetitions, 0 km et 30 s, et
     * l'utilisateur corrige ensuite. Une serie validee sans avoir ete touchee
     * garde ces valeurs : la retenir comme historique proposerait 0 kg a la
     * seance suivante, et ce 0 se propagerait de seance en seance. Une serie de
     * poids de corps (0 kg mais des repetitions saisies) reste un historique.
     */
    private function estRestéeAuPréRemplissage(Set $set): bool
    {
        $defauts = $this->getDefaultValues();

        return (float) ($set->weight ?? 0.0) === $defauts['weight']
            && (int) ($set->reps ?? $defauts['reps']) === $defauts['reps']
            && (float) ($set->distance_km ?? 0.0) === $defauts['distance_km']
            && in_array((int) ($set->duration_seconds ?? $defauts['duration_seconds']), [0, $defauts['duration_seconds']], true);
    }

    /**
     * Calculate recommended values from the most recent informative line.
     *
     * Lines are examined from the most recent to the oldest. The first one
     * that holds at least one set touched by the user provides the most
     * frequent combination of weight, reps, distance, and duration among
     * those sets. Lines with no set, or only untouched sets, are skipped.
     *
     * @param  Collection<int, WorkoutLine>  $lines  Previous lines for one exercise, most recent first.
     * @return array{weight: float, reps: int, distance_km: float, duration_seconds: int} The most commonly used set parameters.
     */
    private function calculateFromLines(Collection $lines): array
    {
        foreach ($lines as $line) {
            $sets = $line->sets->reject(fn (Set $set): bool => $this->estRestéeAuPréRemplissage($set));

            if ($sets->isEmpty()) {
                continue;
            }

            $frequencies = $sets->groupBy(fn (Set $set): string => "{$set->weight}-{$set->reps}-{$set->distance_km}-{$set->duration_seconds}")
                ->map(fn ($group): int => $group->count());

            $mostFrequentKey = (string) $frequencies->sortDesc()->keys()->first();
            [$weight, $reps, $distance, $duration] = explode('-', $mostFrequentKey);

            return [
                'weight' => (float) $weight,
                'reps' => (int) $reps,
                'distance_km' => (float) $distance,
                'duration_seconds' => (int) $duration,
            ];
        }

        return $this->getDefaultValues();
    }

    /**
     * Retrieve recommended values from cache, or fetch them if missing.
     *
     * Iterates through required exercise IDs. Yields cached results if available;
     * otherwise, delegates to fetch the uncached values from the database and
     * merges them into the final result set.
     *
     * @param  array<int, int>  $exerciseIds  The list of exercise IDs to retrieve values for.
     * @param  int  $userId  The user ID associated with the recommendations.
     * @param  int  $workoutId  The current workout ID.
     * @param  Workout  $workout  The current workout instance.
     * @return array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}> Results mapped by exercise ID.
     */
    private function getResultsFromCacheOrFetch(array $exerciseIds, int $userId, int $workoutId, Workout $workout): array
    {
        $results = [];
        $uncachedExerciseIds = [];
        $cacheKeys = [];

        foreach ($exerciseIds as $exerciseId) {
            $exerciseIdInt = (int) $exerciseId;
            $cacheKeys[$exerciseIdInt] = self::cleDeCache($userId, $exerciseIdInt, $workoutId);
        }

        /** @var array<string, array{weight: float, reps: int, distance_km: float, duration_seconds: int}|null> $cachedMany */
        $cachedMany = Cache::many($cacheKeys);

        foreach ($cacheKeys as $exerciseIdInt => $cacheKey) {
            $cached = $cachedMany[$cacheKey];
            if ($cached !== null) {
                $results[$exerciseIdInt] = $cached;
            } else {
                $uncachedExerciseIds[] = $exerciseIdInt;
            }
        }

        if (count($uncachedExerciseIds) > 0) {
            $uncachedResults = $this->fetchUncachedRecommendedValues($uncachedExerciseIds, $workoutId, $userId, $workout);
            foreach ($uncachedResults as $exerciseIdInt => $values) {
                $results[$exerciseIdInt] = $values;
            }
        }

        return $results;
    }

    /**
     * Fetch un-cached recommended values directly from the database.
     *
     * Ranks the previous lines of each requested exercise by date, keeps the
     * most recent ones, calculates the recommended values from the first
     * informative line, caches the individual results, and returns them.
     *
     * @param  array<int, int>  $uncachedExerciseIds  Exercise IDs lacking cached data.
     * @param  int  $workoutId  The current workout ID (to exclude from search).
     * @param  int  $userId  The user ID to constrain the search.
     * @param  Workout  $workout  The current workout to establish the timeline limit.
     * @return array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}> Newly calculated results mapped by exercise ID.
     */
    private function fetchUncachedRecommendedValues(array $uncachedExerciseIds, int $workoutId, int $userId, Workout $workout): array
    {
        $results = [];

        /*
         * La meme question que le chemin unitaire, et la meme reponse.
         *
         * Ce lot prenait `MAX(workout_lines.id)` quand l'unitaire triait par
         * `workouts.started_at` : sur une seance saisie apres coup, les deux
         * designaient des lignes differentes. L'ordre porte sur la date des
         * deux cotes, departage par la clef primaire dans le meme sens, et
         * chaque exercice remonte ses dernieres lignes, pas seulement une.
         */
        $classement = WorkoutLine::query()
            ->select(['id', 'exercise_id'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY exercise_id ORDER BY workout_started_at DESC, id DESC) AS rang')
            ->where('user_id', $userId)
            ->whereIn('exercise_id', $uncachedExerciseIds)
            ->where('workout_id', '!=', $workoutId)
            ->where('workout_started_at', '<', $workout->started_at);

        $retenues = DB::query()
            ->select('id')
            ->fromSub($classement, 'classement')
            ->where('rang', '<=', self::PROFONDEUR);

        $candidatesByExercise = WorkoutLine::query()
            ->with('sets')
            ->whereIn('id', $retenues)
            ->orderByDesc('workout_started_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('exercise_id');

        $cacheData = [];
        foreach ($uncachedExerciseIds as $exerciseId) {
            /** @var Collection<int, WorkoutLine> $candidates */
            $candidates = $candidatesByExercise->get($exerciseId, new Collection());
            $values = $this->calculateFromLines($candidates);

            $cacheData[self::cleDeCache($userId, $exerciseId, $workoutId)] = $values;
            $results[$exerciseId] = $values;
        }

        if (count($cacheData) > 0) {
            Cache::putMany($cacheData, 300);
        }

        return $results;
    }
}
