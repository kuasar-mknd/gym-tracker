<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

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
    public function __construct(protected VolumeStatsService $volumeStats, protected WorkoutStatsService $workoutStats)
    {
    }

    /**
     * Fetch workouts and related statistics for the index page.
     *
     * @return array{
     *     workouts: \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\Workout>,
     *     totalExercises: int
     * }
     */
    public function execute(User $user): array
    {
        return [
            'workouts' => $this->seancesDeLUtilisateur($user),
            // Distinct exercises, not workout lines. The card sits beside
            // "Total séances" and is labelled "Exercices", so it reads as a
            // count of entities — but workoutLines() is every line of every
            // session, so a bench press done in 40 sessions counted 40 times.
            // The figure had no relation to the library's "N exercices
            // disponibles" or to the Stats page's own Exercices card.
            'totalExercises' => $this->getTotalExercises($user),
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
                'monthly_frequency' => $this->getMonthlyFrequency($user, $maintenant),
                'day_of_week_frequency' => $this->getDayOfWeekFrequency($user, $maintenant),
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
     * avant de se corriger.
     */
    protected function getTotalExercises(User $user): int
    {
        return (int) Cache::remember(
            ClesDeStats::seances($user, 'total_exercises'),
            now()->addHour(),
            fn (): int => $this->compterExercicesDistincts($user)
        );
    }

    /**
     * Combien d'exercices différents cet utilisateur a déjà faits.
     *
     * Le chiffre ne dépend que de la BIBLIOTHÈQUE de l'utilisateur, pas de son
     * historique : il se lit en sautant dans l'index d'un exercice au suivant,
     * sans parcourir les lignes. MySQL fait ce saut lui-même (« Using index
     * for group-by », « Covering index skip scan for deduplication ») quand
     * les colonnes du `distinct` forment le PRÉFIXE d'un index : d'où
     * `user_id` devant `exercise_id`, sur
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
     * soit E + 3 lectures, en une instruction : moins d'une demi-milliseconde
     * (médianes), contre 11 et près de 80 ms pour la CTE. Celle-ci promettait
     * une lecture par exercice, mais son membre récursif était une
     * sous-requête dépendante que MySQL ne réduit pas à une plage : elle
     * relisait toutes les lignes du compte à chaque exercice, E × L lectures.
     * La boucle PHP de `min()` coûte E + 1 lectures, mais autant
     * d'allers-retours (81 requêtes, 10 à 12 ms à quatre-vingts exercices),
     * et une boucle sans borne connue n'a pas sa place dans le code muté
     * (`.ai/rules/mutation.md`).
     *
     * Le saut reste un choix de coût, que MySQL fait d'après les statistiques
     * de la table. Il a été retenu dans chaque cas mesuré où elles
     * connaissaient les lignes (après `ANALYZE TABLE` ou le recalcul
     * automatique d'InnoDB) : compte dense, clairsemé, un exercice par ligne
     * (E + 3 = L + 3), compte sans ligne. Tant qu'elles ne les connaissent
     * pas, MySQL lit les lignes du compte (L + 1, le coût de
     * `count(distinct exercise_id)`) : dans la base des tests, dont les
     * écritures ne sont jamais validées, et à la première requête qui suit
     * une écriture en masse (9 001 lectures, puis 83 à la suivante, avant
     * même le recalcul). Jamais E × L.
     */
    private function compterExercicesDistincts(User $user): int
    {
        return WorkoutLine::query()
            ->toBase()
            ->where('user_id', $user->id)
            ->distinct()
            ->count(['user_id', 'exercise_id']);
    }

    /**
     * @param  Carbon  $maintenant  L'instant de l'opération, dont viennent la clef et la fenêtre.
     * @return Collection<int, array{day: string, count: int}>
     */
    protected function getDayOfWeekFrequency(User $user, Carbon $maintenant): Collection
    {
        return Cache::remember(
            ClesDeStats::seances($user, 'day_of_week_frequency.'.self::moisCourant($maintenant)),
            $maintenant->copy()->addHour(),
            fn (): Collection => $this->calculateDayOfWeekFrequency($user, self::debutDeLaFenetre($maintenant))
        );
    }

    /**
     * @param  Carbon  $maintenant  L'instant de l'opération, dont viennent la clef et la fenêtre.
     * @return Collection<int, array{month: string, count: int}>
     */
    protected function getMonthlyFrequency(
        User $user,
        Carbon $maintenant
    ): Collection {
        return Cache::remember(
            ClesDeStats::seances($user, 'monthly_frequency.'.self::moisCourant($maintenant)),
            $maintenant->copy()->addHour(),
            fn (): Collection => $this->calculateMonthlyFrequency($user, self::debutDeLaFenetre($maintenant))
        );
    }

    /**
     * Le debut des six mois glissants qu'affichent les cartes de frequence.
     *
     * Six MOIS CALENDAIRES et non cent quatre-vingts jours : la carte mensuelle
     * dessine six barres, dont celle du mois en cours.
     *
     * Le recul part du premier du mois, jamais d'aujourd'hui : Carbon deborde,
     * et le 31 juillet moins cinq mois donnait le « 31 fevrier », soit le
     * 3 mars — fevrier sortait de la fenetre (#1955).
     *
     * @param  Carbon  $maintenant  L'instant de l'opération, que la fenêtre ne modifie pas.
     */
    private static function debutDeLaFenetre(Carbon $maintenant): Carbon
    {
        return $maintenant->copy()->startOfMonth()->subMonths(5);
    }

    /**
     * Le mois courant, que portent les clefs des deux cartes de frequence.
     *
     * Leur fenetre suit le mois calendaire : sans lui dans la clef, le premier
     * du mois servait encore, jusqu'a l'expiration, les six mois de la veille.
     * Il vient du même instant que la fenêtre, pour que la clef dise le mois
     * de la fenêtre qu'elle garde (#2033).
     *
     * @param  Carbon  $maintenant  L'instant de l'opération.
     */
    private static function moisCourant(Carbon $maintenant): string
    {
        return $maintenant->format('Y-m');
    }

    /**
     * @param  Carbon  $startDate  Le premier jour de la fenêtre.
     * @return Collection<
     *     int,
     *     array{month: string, count: int}
     * >
     */
    private function calculateMonthlyFrequency(
        User $user,
        Carbon $startDate
    ): Collection {
        // ⚡ Bolt Optimization: Group and count directly in SQL to reduce memory usage and CPU cycles in PHP.
        // Also uses toBase() to avoid Eloquent model hydration.
        $results = Workout::query()
            ->toBase()
            ->where('user_id', $user->id)
            ->where('started_at', '>=', $startDate)
            ->selectRaw("DATE_FORMAT(started_at, '%Y-%m') as month, COUNT(*) as count")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        /*
         * Chaque barre avance d'un mois depuis le premier jour de la fenetre :
         * reculer depuis aujourd'hui sautait et doublait des mois les 29, 30
         * et 31 (#1955).
         */
        return collect(range(0, 5))->map(function (int $i) use ($results, $startDate): array {
            $date = $startDate->copy()->addMonths($i);
            $monthKey = $date->format('Y-m');
            $data = $results->get($monthKey);

            return [
                'month' => $date->translatedFormat('M'),
                'count' => $data !== null && is_numeric($data->count) ? (int) $data->count : 0,
            ];
        });
    }

    /**
     * @param  Carbon  $debut  Le premier jour de la fenêtre, celui de la fréquence mensuelle.
     * @return Collection<int, array{day: string, count: int}>
     */
    private function calculateDayOfWeekFrequency(User $user, Carbon $debut): Collection
    {
        /*
         * La MEME fenetre que la frequence mensuelle, sa voisine immediate.
         *
         * Celle-ci n'en avait aucune : elle lisait toutes les seances du compte
         * pour une carte posee a cote d'une autre bornee a six mois, et aucune
         * des deux n'annoncait la sienne. Mesure aux compteurs
         * `Handler_read_*` : 109 lectures d'index a 30 seances anciennes,
         * 1 249 a 600 — la derniere page du produit dont le cout suivait
         * l'anciennete du compte.
         *
         * Les deux la tirent desormais de `debutDeLaFenetre()`, et du même
         * instant, pour qu'elles ne puissent plus diverger, et les deux
         * sous-titres l'annoncent. Chacune lisait l'horloge : quand le mois
         * changeait entre les deux lectures, elles divergeaient encore (#2033).
         */
        $results = Workout::query()
            ->toBase()
            ->where('user_id', $user->id)
            ->where('started_at', '>=', $debut)
            ->selectRaw('DAYOFWEEK(started_at) as day_of_week, COUNT(*) as count')
            ->groupBy('day_of_week')
            ->get()
            ->keyBy('day_of_week');

        $jours = [
            2 => 'Lun',
            3 => 'Mar',
            4 => 'Mer',
            5 => 'Jeu',
            6 => 'Ven',
            7 => 'Sam',
            1 => 'Dim',
        ];

        return collect($jours)->map(function (string $dayName, int $dayIndex) use ($results): array {
            $data = $results->get($dayIndex);

            return [
                'day' => $dayName,
                'count' => $data !== null && is_numeric($data->count) ? (int) $data->count : 0,
            ];
        })->values();
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
                $query->with('exercise')->withCount('sets');
            },
        ])
            ->where('user_id', $user->id)
            ->latest('started_at')
            ->paginate(20);
    }
}
