<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Services\Stats\ClesDeStats;
use App\Services\Stats\VolumeStatsService;
use App\Services\Stats\WorkoutStatsService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
     * Un COUNT DISTINCT sur toutes les lignes de toutes les seances.
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
     * Combien d'exercices differents cet utilisateur a deja faits.
     *
     * `distinct()->count()` lisait toutes les lignes de toutes les seances :
     * 321 lectures d'index a 40 seances, 1 601 a 200, pour rendre un chiffre
     * qui ne depend que de la BIBLIOTHEQUE de l'utilisateur. Un `group by`
     * n'aide pas — MySQL ne retient pas de balayage lache ici, `Extra` restant
     * « Using index » et jamais « Using index for group-by ».
     *
     * Le saut, lui, coute une lecture par exercice trouve : `min(exercise_id)`
     * au-dela du curseur forme une plage sur
     * `(user_id, exercise_id, workout_started_at)` et s'arrete a la premiere
     * entree. Neuf lectures aux deux profondeurs mesurees. Meme recette que
     * `FetchBodyPartMeasurementsIndexAction`, et meme raison.
     *
     * La boucle tourne dans la base, par une expression de table recursive :
     * une instruction, quel que soit le nombre d'exercices, la ou une boucle
     * PHP coutait un aller-retour par exercice trouve.
     */
    private function compterExercicesDistincts(User $user): int
    {
        $compte = DB::scalar(
            'with recursive saut as ('
            .'select min(exercise_id) as exercise_id from workout_lines where user_id = ? '
            .'union all '
            .'select (select min(exercise_id) from workout_lines where user_id = ? and exercise_id > saut.exercise_id) '
            .'from saut where saut.exercise_id is not null'
            .') select count(exercise_id) from saut',
            [$user->id, $user->id],
        );

        return is_numeric($compte) ? (int) $compte : 0;
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
