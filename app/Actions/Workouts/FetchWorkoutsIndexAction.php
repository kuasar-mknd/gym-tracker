<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Actions\Stats\FetchRegulariteAction;
use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\Stats\ClesDeStats;
use App\Services\Stats\VolumeStatsService;
use App\Services\Stats\WorkoutStatsService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class FetchWorkoutsIndexAction
{
    public function __construct(
        protected VolumeStatsService $volumeStats,
        protected WorkoutStatsService $workoutStats,
        protected FetchRegulariteAction $fetchRegularite
    ) {
    }

    /**
     * Les props immédiates de la page des séances : la page de séances et le
     * compte des exercices distincts.
     *
     * Chacune est une fermeture, qu'Inertia ne résout que si sa prop part dans
     * la réponse : la requête de la prop différée `deferredData`, qui rejoue le
     * contrôleur, relisait sinon la pagination et le compte pour les jeter
     * aussitôt (#2148, étape 3a). L'horloge se lit une fois, ici, et les
     * fermetures en gardent l'instant ; aucune ne la relit.
     *
     * @return array{
     *     workouts: \Closure(): \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\Workout>,
     *     totalExercises: \Closure(): int
     * }
     */
    public function execute(User $user): array
    {
        $maintenant = now();

        return [
            'workouts' => fn (): \Illuminate\Pagination\LengthAwarePaginator => $this->seancesDeLUtilisateur($user),
            // Distinct exercises, not workout lines. The card sits beside
            // "Total séances" and is labelled "Exercices", so it reads as a
            // count of entities — but workoutLines() is every line of every
            // session, so a bench press done in 40 sessions counted 40 times.
            // The figure had no relation to the library's "N exercices
            // disponibles" or to the Stats page's own Exercices card.
            'totalExercises' => fn (): int => $this->getTotalExercises($user, $maintenant),
        ];
    }

    /**
     * Get consolidated deferred data (charts and exercises).
     * ⚡ Bolt: Reduces 2 deferred prop XHR requests to 1.
     *
     * Les trois cartes qui suivent le mois calendaire — les deux fréquences et
     * le volume mensuel — reçoivent le même instant, lu une fois : chacune
     * lisait l'horloge, et la clef de chaque fréquence d'une autre lecture que
     * sa fenêtre. Quand le mois changeait entre deux lectures, deux cartes
     * voisines comptaient deux fenêtres différentes, ou l'entrée rangée sous
     * le mois qui s'achevait gardait la fenêtre du mois suivant (#2033).
     *
     * @return array{
     *     charts: array{
     *         monthly_frequency: Collection<int, array{month: string, count: int}>,
     *         day_of_week_frequency: Collection<int, array{day: string, count: int}>,
     *         monthly_volume: array<int, \App\DTOs\Stats\MonthlyVolumePoint>,
     *         duration_history: array<int, \App\DTOs\Stats\DurationHistoryPoint>,
     *         volume_history: array<int, \App\DTOs\Stats\VolumeHistoryPoint>
     *     },
     *     exercises: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Exercise>
     * }
     */
    public function getDeferredData(User $user): array
    {
        $maintenant = now();

        return [
            'charts' => [
                ...$this->fetchRegularite->execute($user, $maintenant),
                'monthly_volume' => $this->volumeStats->getMonthlyVolumeHistory($user, 6, $maintenant),
                'duration_history' => $this->workoutStats->getDurationHistory($user, 20),
                'volume_history' => $this->volumeStats->getVolumeHistory($user, 20),
            ],
            'exercises' => Exercise::enCachePourUtilisateur($user->id),
        ];
    }

    /**
     * Le compte des exercices distincts (`compterExercicesDistincts()`).
     *
     * Il restait immediat pendant que le reste de la page passait en differe et
     * en cache — et la requete XHR de la prop differee rejoue le controleur, si
     * bien qu'il partait deux fois par affichage. Meme cache d'une heure que ses
     * voisines, plutot que de le rendre differe : la carte afficherait sinon 0
     * avant de se corriger. Depuis qu'il est une fermeture (#2148, étape 3a),
     * cette requête ne le calcule plus du tout.
     *
     * @param  Carbon  $maintenant  L'instant de l'opération, dont vient l'échéance de l'entrée.
     */
    protected function getTotalExercises(User $user, Carbon $maintenant): int
    {
        return (int) Cache::remember(
            ClesDeStats::seances($user, 'total_exercises'),
            $maintenant->copy()->addHour(),
            fn (): int => $this->compterExercicesDistincts($user)
        );
    }

    /**
     * Combien d'exercices différents cet utilisateur a déjà faits.
     *
     * Le chiffre ne dépend que de la BIBLIOTHÈQUE de l'utilisateur, pas de son
     * historique : il peut se lire en sautant dans l'index d'un exercice au
     * suivant, sans parcourir les lignes. MySQL ne fait ce saut lui-même
     * (« Using index for group-by », « Covering index skip scan for
     * deduplication ») que si les colonnes du `distinct` forment le PRÉFIXE
     * d'un index : d'où `user_id` devant `exercise_id`, sur
     * `(user_id, exercise_id, workout_started_at)`. Le compte n'en change pas,
     * toutes les lignes lues portant le même `user_id`. `count(distinct
     * exercise_id)` seul n'est pas un préfixe, et lit chaque ligne du compte ;
     * un `group by exercise_id` de même.
     *
     * Deltas de `Handler_read%`, MySQL 8.4.11 et 9.7.2, deux comptes voisins
     * dans la table (#2106) :
     *
     *     exercices  lignes  ce compte  count(distinct exercise_id)  CTE récursive
     *            40   2 500         43                        2 501        100 124
     *            80   9 000         83                        9 001        720 244
     *
     * soit E + 3 lectures quand MySQL saute, en une instruction : moins d'une
     * demi-milliseconde (médianes), contre 11 et près de 80 ms pour la CTE.
     * Celle-ci promettait une lecture par exercice, mais son membre récursif
     * était une sous-requête dépendante que MySQL ne réduit pas à une plage :
     * elle relisait toutes les lignes du compte à chaque exercice, E × L
     * lectures.
     * La boucle PHP de `min()` coûte E + 1 lectures, mais autant
     * d'allers-retours (81 requêtes, 10 à 12 ms à quatre-vingts exercices),
     * et une boucle sans borne connue n'a pas sa place dans le code muté
     * (`.ai/rules/mutation.md`).
     *
     * Le saut est un choix de coût, et ce n'est pas la fraîcheur des
     * statistiques qui le décide : MySQL le choisit sur le nombre de groupes
     * qu'il estime pour le compte, d'après la cardinalité du préfixe
     * `(user_id, exercise_id)` sur TOUTE la table, donc selon la façon dont
     * se répartissent les autres comptes. Sinon, il lit les lignes du compte
     * (L + 1, le coût de `count(distinct exercise_id)`). Mesuré sur 8.4.11,
     * juste après `ANALYZE TABLE` : un compte d'un exercice sur 320 lignes lit
     * 321 entrées à côté de quatre comptes de 200 à 1 100 exercices d'une
     * ligne chacun, puis 4 une fois ces comptes partis ; 9.7.2 saute aussi
     * sur des statistiques périmées. Le coût est donc L + 1 ou E + 3 selon le
     * plan, au plus L + 3 (un exercice par ligne, lu par saut), jamais E × L.
     * La base des tests n'y échappe pas : ses statistiques suivent ce que le
     * recalcul automatique d'InnoDB a échantillonné, lignes jamais validées
     * des tests voisins comprises (`.ai/rules/services.md`, piège 5).
     */
    private function compterExercicesDistincts(User $user): int
    {
        return WorkoutLine::query()
            ->toBase()
            ->where('user_id', $user->id)
            ->distinct('user_id', 'exercise_id')
            ->count();
    }

    /** @return \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\Workout> */
    private function seancesDeLUtilisateur(
        User $user
    ): \Illuminate\Pagination\LengthAwarePaginator {
        return Workout::with([
            'workoutLines' => function (Relation $query): void {
                /*
                 * `with()` declare `Closure(Relation<*, *, *>)` : la fermeture
                 * doit accepter le type le plus large, un `HasMany` n'est pas
                 * contravariant avec lui. Ici c'est un WorkoutLine de Workout.
                 */
                $query->select(['id', 'workout_id', 'exercise_id', 'order'])
                    ->with('exercise:id,name')
                    ->withCount('sets');
            },
        ])
            ->where('user_id', $user->id)
            ->latest('started_at')
            ->orderByDesc('id')
            ->paginate(20);
    }
}
