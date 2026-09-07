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
 * Propose le poids, les répétitions, la distance et la durée d'un exercice
 * d'après ce que l'utilisateur a fait les fois précédentes.
 *
 * Ces valeurs pré-remplissent chaque série à l'ouverture d'une séance : elles
 * sont donc demandées pour tous les exercices à la fois, et mises en cache.
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
    public static function cleDeCache(int $idUtilisateur, int $idExercice, int $idSeance): string
    {
        $version = Cache::get("recommended_values:version:{$idUtilisateur}", 0);

        return "recommended_values:{$idUtilisateur}:v".(is_numeric($version) ? (int) $version : 0).":{$idExercice}:{$idSeance}";
    }

    public function invaliderPour(int $idUtilisateur): void
    {
        Cache::increment("recommended_values:version:{$idUtilisateur}");
    }

    /**
     * Les valeurs proposées pour une ligne de séance.
     *
     * @param  WorkoutLine  $line  La ligne à pré-remplir.
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
     * Les valeurs proposées pour toutes les lignes d'une séance, en un lot.
     *
     * Le chemin unitaire ligne par ligne rouvrait le cache et la base autant de
     * fois qu'il y a d'exercices à l'écran. Les valeurs sont posées au passage
     * sur les modèles reçus.
     *
     * @param  Collection<int, WorkoutLine>  $lines  Les lignes à pré-remplir.
     * @param  int  $idUtilisateur  Le propriétaire des lignes.
     * @return array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}> Indexé par exercice.
     */
    public function batchRecommendedValues(Collection $lines, int $idUtilisateur): array
    {
        if ($lines->isEmpty()) {
            return [];
        }

        $idSeance = $lines->first()->workout_id;
        /** @var array<int, int> $idsExercices */
        $idsExercices = $lines->pluck('exercise_id')->unique()->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->values()->all();

        $workout = $this->resolveWorkout($idSeance, $idsExercices);
        if ($workout === null) {
            return [];
        }

        $defaults = $this->getDefaultValues();
        $results = $this->getResultsFromCacheOrFetch($idsExercices, $idUtilisateur, (int) $idSeance, $workout);

        $this->applyRecommendedValuesToLines($lines, $results, $defaults);

        return $results;
    }

    /**
     * La séance visée, si la question a un sens.
     *
     * Sans identifiant de séance ou sans exercice à servir, il n'y a rien à
     * chercher : la requête est évitée plutôt que lancée pour rien.
     *
     * @param  int|null  $idSeance  L'identifiant de la séance.
     * @param  array<int, int>  $idsExercices  Les exercices à servir.
     */
    private function resolveWorkout(?int $idSeance, array $idsExercices): ?Workout
    {
        if ($idSeance === null || count($idsExercices) === 0) {
            return null;
        }

        return Workout::find($idSeance);
    }

    /**
     * Pose les valeurs sur les lignes, dans un attribut qui n'est pas persisté.
     *
     * @param  Collection<int, WorkoutLine>  $lines  Les lignes à garnir.
     * @param  array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}>  $results  Les valeurs calculées, indexées par exercice.
     * @param  array{weight: float, reps: int, distance_km: float, duration_seconds: int}  $defaults  Le repli quand l'exercice n'a pas d'historique.
     */
    private function applyRecommendedValuesToLines(Collection $lines, array $results, array $defaults): void
    {
        foreach ($lines as $line) {
            $line->setRecommendedValuesAttribute($results[$line->exercise_id] ?? $defaults);
        }
    }

    /**
     * Le pré-remplissage d'une série sans historique.
     *
     * @return array{weight: float, reps: int, distance_km: float, duration_seconds: int}
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
     * Les valeurs tirées de la première ligne qui dit quelque chose.
     *
     * Les lignes sont parcourues de la plus récente à la plus ancienne. La
     * première qui porte au moins une série touchée par l'utilisateur donne la
     * combinaison de poids, répétitions, distance et durée la plus fréquente
     * parmi ces séries-là. Les lignes vides, ou restées au pré-remplissage,
     * sont passées.
     *
     * @param  Collection<int, WorkoutLine>  $lines  Les lignes précédentes d'un exercice, la plus récente en tête.
     * @return array{weight: float, reps: int, distance_km: float, duration_seconds: int}
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
     * Les valeurs du cache, et celles qui manquent calculées d'un coup.
     *
     * Le cache est interrogé pour tous les exercices en une fois ; seuls les
     * absents redescendent en base, ensemble.
     *
     * @param  array<int, int>  $idsExercices  Les exercices à servir.
     * @param  int  $idUtilisateur  Le propriétaire des lignes.
     * @param  int  $idSeance  La séance en cours.
     * @param  Workout  $workout  La séance en cours.
     * @return array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}> Indexé par exercice.
     */
    private function getResultsFromCacheOrFetch(array $idsExercices, int $idUtilisateur, int $idSeance, Workout $workout): array
    {
        $results = [];
        $uncachedExerciseIds = [];
        $cacheKeys = [];

        foreach ($idsExercices as $idExercice) {
            $exerciseIdInt = (int) $idExercice;
            $cacheKeys[$exerciseIdInt] = self::cleDeCache($idUtilisateur, $exerciseIdInt, $idSeance);
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
            $uncachedResults = $this->fetchUncachedRecommendedValues($uncachedExerciseIds, $idSeance, $idUtilisateur, $workout);
            foreach ($uncachedResults as $exerciseIdInt => $values) {
                $results[$exerciseIdInt] = $values;
            }
        }

        return $results;
    }

    /**
     * Les valeurs absentes du cache, calculées en base.
     *
     * Les lignes précédentes de chaque exercice demandé sont classées par date,
     * les plus récentes retenues, et la première qui dit quelque chose donne les
     * valeurs, qui sont mises en cache au passage.
     *
     * @param  array<int, int>  $uncachedExerciseIds  Les exercices absents du cache.
     * @param  int  $idSeance  La séance en cours, exclue de la recherche.
     * @param  int  $idUtilisateur  Le propriétaire des lignes.
     * @param  Workout  $workout  La séance en cours, qui borne l'historique dans le temps.
     * @return array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}> Indexé par exercice.
     */
    private function fetchUncachedRecommendedValues(array $uncachedExerciseIds, int $idSeance, int $idUtilisateur, Workout $workout): array
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
            ->where('user_id', $idUtilisateur)
            ->whereIn('exercise_id', $uncachedExerciseIds)
            ->where('workout_id', '!=', $idSeance)
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
        foreach ($uncachedExerciseIds as $idExercice) {
            /** @var Collection<int, WorkoutLine> $candidates */
            $candidates = $candidatesByExercise->get($idExercice, new Collection());
            $values = $this->calculateFromLines($candidates);

            $cacheData[self::cleDeCache($idUtilisateur, $idExercice, $idSeance)] = $values;
            $results[$idExercice] = $values;
        }

        if (count($cacheData) > 0) {
            Cache::putMany($cacheData, 300);
        }

        return $results;
    }
}
