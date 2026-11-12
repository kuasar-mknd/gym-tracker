<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Set;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Support\VersionDeCache;
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
     * série enregistrée tire une version neuve et rend obsolètes d'un coup
     * toutes les recommandations de l'utilisateur, sans les énumérer. La
     * version est un ULID, jamais réutilisé : perdue par le cache, elle ne
     * ramène plus une recommandation d'avant une série (`VersionDeCache`,
     * #2151).
     */
    public static function cleDeCache(int $idUtilisateur, int $idExercice, int $idSeance): string
    {
        return "recommended_values:{$idUtilisateur}:v".VersionDeCache::lire("recommended_values:version:{$idUtilisateur}").":{$idExercice}:{$idSeance}";
    }

    public function invaliderPour(int $idUtilisateur): void
    {
        VersionDeCache::renouveler("recommended_values:version:{$idUtilisateur}");
    }

    /**
     * Les valeurs proposées pour une ligne de séance.
     *
     * Le même calcul que le lot, pour le seul exercice de la ligne (#1513) :
     * la ligne seule avait sa requête, son calcul et son écriture de la même
     * clef, et les deux chemins avaient déjà répondu différemment sur les
     * mêmes séances. L'historique est celui du propriétaire de la séance de
     * la ligne ; la ligne ne reçoit pas les valeurs en attribut, que
     * l'accesseur qui l'appelle lirait ensuite comme une colonne modifiée.
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

        $idExercice = (int) $line->exercise_id;
        $results = $this->getResultsFromCacheOrFetch([$idExercice], (int) $workout->user_id, (int) $line->workout_id, $workout);

        return $results[$idExercice] ?? $this->getDefaultValues();
    }

    /**
     * Les valeurs proposées pour toutes les lignes d'une séance, en un lot.
     *
     * Le chemin unitaire ligne par ligne rouvrait le cache et la base autant de
     * fois qu'il y a d'exercices à l'écran. Les valeurs sont posées au passage
     * sur les modèles reçus.
     *
     * La séance des lignes borne l'historique dans le temps. L'appelant qui
     * l'a déjà chargée la passe : la page de la séance la tient de la route,
     * et la relire par son identifiant coûtait une requête de plus à chaque
     * visite et à chaque rechargement de la prop `workout` (#2148, étape 3c).
     * Sans elle, c'est celle de la première ligne, lue au besoin. L'historique
     * reste celui de `$idUtilisateur`, quelle que soit la séance.
     *
     * @param  Collection<int, WorkoutLine>  $lines  Les lignes à pré-remplir, d'une même séance.
     * @param  int  $idUtilisateur  Le propriétaire des lignes.
     * @param  Workout|null  $seance  La séance des lignes, quand l'appelant l'a déjà chargée.
     * @return array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}> Indexé par exercice.
     *
     * @throws \InvalidArgumentException Si la séance passée n'est pas celle des lignes.
     */
    public function batchRecommendedValues(Collection $lines, int $idUtilisateur, ?Workout $seance = null): array
    {
        $premiere = $lines->first();

        if ($premiere === null) {
            return [];
        }

        // Une ligne qu'on vient de fabriquer peut n'avoir encore aucune séance.
        $idDeLaSeance = $premiere->getAttribute('workout_id');
        /** @var array<int, int> $idsExercices */
        $idsExercices = $lines->pluck('exercise_id')->unique()->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->values()->all();

        // Sans séance ou sans exercice à servir, il n'y a rien à chercher : aucune requête.
        if (! is_numeric($idDeLaSeance) || count($idsExercices) === 0) {
            return [];
        }

        $idSeance = (int) $idDeLaSeance;
        $workout = $this->seanceDesLignes($premiere, $idSeance, $seance);

        if ($workout === null) {
            return [];
        }

        $defaults = $this->getDefaultValues();
        $results = $this->getResultsFromCacheOrFetch($idsExercices, $idUtilisateur, $idSeance, $workout);

        $this->applyRecommendedValuesToLines($lines, $results, $defaults);

        return $results;
    }

    /**
     * La séance des lignes : celle que l'appelant a passée, qui doit être la
     * leur, sinon celle de la première ligne, chargée seulement si elle ne
     * l'est pas déjà.
     *
     * @param  WorkoutLine  $premiere  La première des lignes.
     * @param  int  $idSeance  L'identifiant de la séance des lignes.
     * @param  Workout|null  $seance  La séance que l'appelant a passée.
     *
     * @throws \InvalidArgumentException Si la séance passée n'est pas celle des lignes.
     */
    private function seanceDesLignes(WorkoutLine $premiere, int $idSeance, ?Workout $seance): ?Workout
    {
        if ($seance !== null) {
            if ($seance->id !== $idSeance) {
                throw new \InvalidArgumentException('La séance passée n’est pas celle des lignes.');
            }

            return $seance;
        }

        $premiere->loadMissing('workout');
        $seanceDeLaLigne = $premiere->getRelation('workout');

        return $seanceDeLaLigne instanceof Workout ? $seanceDeLaLigne : null;
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
     * poids de corps reste un historique des qu'une valeur differe du
     * pre-remplissage ; a 10 repetitions pile, 0 kg, 0 km et 0 ou 30 s, elle
     * est indiscernable d'une serie non touchee et est donc passee.
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
     * Chaque valeur est ramenée sous le plafond de son champ (`Set::bornes()`) :
     * une série enregistrée avant ces plafonds peut les dépasser, et la page
     * envoie la recommandation telle quelle à la création de la série
     * suivante, que la requête refuserait alors à chaque essai.
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
                'weight' => (float) Set::ramenerALaBorne('weight', (float) $weight),
                'reps' => (int) Set::ramenerALaBorne('reps', (int) $reps),
                'distance_km' => (float) Set::ramenerALaBorne('distance_km', (float) $distance),
                'duration_seconds' => (int) Set::ramenerALaBorne('duration_seconds', (int) $duration),
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
     * valeurs, qui sont mises en cache au passage. C'est le seul endroit qui
     * écrit la clef (#1513, `LesProjectionsGardentLeursEcrivainsTest`) : la
     * ligne seule et le lot y passent tous les deux.
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
        $candidatesByExercise = $this->lignesPrecedentes($uncachedExerciseIds, $idSeance, $idUtilisateur, $workout)->groupBy('exercise_id');

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

    /**
     * Les `PROFONDEUR` dernières lignes de chaque exercice demandé, d'avant la
     * séance et hors d'elle, la plus récente en tête, séries chargées.
     *
     * L'ordre porte sur la date recopiée sur la ligne, départagée par la clef
     * primaire dans le même sens : une séance saisie après coup a
     * l'identifiant le plus grand et la date la plus ancienne. Le lot prenait
     * `MAX(workout_lines.id)` quand la ligne seule triait par la date, et les
     * deux désignaient alors des lignes différentes.
     *
     * Deux formes, que le nombre d'exercices choisit, et qui rendent les mêmes
     * lignes. Pour un seul (une ligne seule, ou un seul exercice absent du
     * cache), l'index `(user_id, exercise_id, workout_started_at)` sert le
     * filtre et l'ordre, et le `LIMIT` s'arrête aux dernières lignes ; la
     * fenêtre lisait tout l'historique de l'exercice pour en garder cinq.
     * Pour plusieurs, une seule requête, qui classe les lignes de chacun par
     * `ROW_NUMBER()`, plutôt qu'une requête par exercice. Mesuré le
     * 2026-11-11 sur 8.4.13 et 9.7.2 (deltas de `Handler_read%`, 400 séances
     * du compte et autant d'un voisin) : un seul exercice lit 6 entrées
     * d'index, 15 suivantes et 14 à rebours, quand la fenêtre en lisait 11,
     * 816 suivantes et 407 de table ; à 100 séances, 6 entrées et 216
     * suivantes contre 11, 216 et 107 de table, le serveur prenant un autre
     * parcours sur une table aussi petite (`.ai/rules/services.md`, piège 2).
     * La ligne seule lit comme avant, et le lot de trois exercices aussi.
     *
     * @param  array<int, int>  $idsExercices  Les exercices demandés.
     * @param  int  $idSeance  La séance en cours, exclue de la recherche.
     * @param  int  $idUtilisateur  Le propriétaire des lignes.
     * @param  Workout  $seance  La séance en cours, qui borne l'historique dans le temps.
     * @return Collection<int, WorkoutLine>
     */
    private function lignesPrecedentes(array $idsExercices, int $idSeance, int $idUtilisateur, Workout $seance): Collection
    {
        $precedentes = WorkoutLine::query()
            ->where('user_id', $idUtilisateur)
            ->whereIn('exercise_id', $idsExercices)
            ->where('workout_id', '!=', $idSeance)
            ->where('workout_started_at', '<', $seance->started_at);

        if (count($idsExercices) === 1) {
            return $precedentes
                ->with('sets')
                ->orderByDesc('workout_started_at')
                ->orderByDesc('id')
                ->limit(self::PROFONDEUR)
                ->get();
        }

        $classement = $precedentes
            ->select(['id', 'exercise_id'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY exercise_id ORDER BY workout_started_at DESC, id DESC) AS rang');

        $retenues = DB::query()
            ->select('id')
            ->fromSub($classement, 'classement')
            ->where('rang', '<=', self::PROFONDEUR);

        return WorkoutLine::query()
            ->with('sets')
            ->whereIn('id', $retenues)
            ->orderByDesc('workout_started_at')
            ->orderByDesc('id')
            ->get();
    }
}
