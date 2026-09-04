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
            'workouts' => $this->getWorkouts($user),
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
        return [
            'charts' => [
                'monthly_frequency' => $this->getMonthlyFrequency($user),
                'day_of_week_frequency' => $this->getDayOfWeekFrequency($user),
                'monthly_volume' => $this->volumeStats->getMonthlyVolumeHistory($user, 6),
                'duration_history' => $this->workoutStats->getDurationHistory($user, 20),
                'volume_history' => $this->volumeStats->getVolumeHistory($user, 20),
            ],
            'exercises' => Exercise::getCachedForUser($user->id),
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
     */
    private function compterExercicesDistincts(User $user): int
    {
        $compte = 0;
        $curseur = 0;

        while (true) {
            $suivant = DB::table('workout_lines')
                ->where('user_id', $user->id)
                ->where('exercise_id', '>', $curseur)
                ->min('exercise_id');

            if (! is_numeric($suivant)) {
                return $compte;
            }

            $curseur = (int) $suivant;
            $compte++;
        }
    }

    /**
     * @return Collection<int, array{day: string, count: int}>
     */
    protected function getDayOfWeekFrequency(User $user): Collection
    {
        return Cache::remember(
            ClesDeStats::seances($user, 'day_of_week_frequency'),
            now()->addHour(),
            fn (): Collection => $this->calculateDayOfWeekFrequency($user)
        );
    }

    /**
     * @return Collection<int, array{month: string, count: int}>
     */
    protected function getMonthlyFrequency(
        User $user
    ): Collection {
        return Cache::remember(
            ClesDeStats::seances($user, 'monthly_frequency'),
            now()->addHour(),
            fn (): Collection => $this->calculateMonthlyFrequency($user)
        );
    }

    /**
     * Le debut des six mois glissants qu'affichent les cartes de frequence.
     *
     * Six MOIS CALENDAIRES et non cent quatre-vingts jours : la carte mensuelle
     * dessine six barres, dont celle du mois en cours.
     */
    private static function debutDeLaFenetre(): \Illuminate\Support\Carbon
    {
        return now()->subMonths(5)->startOfMonth();
    }

    /**
     * @return Collection<
     *     int,
     *     array{month: string, count: int}
     * >
     */
    private function calculateMonthlyFrequency(
        User $user
    ): Collection {
        $startDate = self::debutDeLaFenetre();

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

        return collect(range(0, 5))->map(function (int $i) use ($results): array {
            $date = now()->subMonths(5 - $i);
            $monthKey = $date->format('Y-m');
            $data = $results->get($monthKey);

            return [
                'month' => $date->translatedFormat('M'),
                'count' => $data !== null && is_numeric($data->count) ? (int) $data->count : 0,
            ];
        });
    }

    /**
     * @return Collection<int, array{day: string, count: int}>
     */
    private function calculateDayOfWeekFrequency(User $user): Collection
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
         * Les deux la tirent desormais de `debutDeLaFenetre()`, pour qu'elles ne
         * puissent plus diverger, et les deux sous-titres l'annoncent.
         */
        $results = Workout::query()
            ->toBase()
            ->where('user_id', $user->id)
            ->where('started_at', '>=', self::debutDeLaFenetre())
            ->selectRaw('DAYOFWEEK(started_at) as day_of_week, COUNT(*) as count')
            ->groupBy('day_of_week')
            ->get()
            ->keyBy('day_of_week');

        $days = [
            2 => 'Lun',
            3 => 'Mar',
            4 => 'Mer',
            5 => 'Jeu',
            6 => 'Ven',
            7 => 'Sam',
            1 => 'Dim',
        ];

        return collect($days)->map(function (string $dayName, int $dayIndex) use ($results): array {
            $data = $results->get($dayIndex);

            return [
                'day' => $dayName,
                'count' => $data !== null && is_numeric($data->count) ? (int) $data->count : 0,
            ];
        })->values();
    }

    /** @return \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\Workout> */
    private function getWorkouts(
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
