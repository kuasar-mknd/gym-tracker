<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\DTOs\Stats\MonthlyVolumePoint;
use App\DTOs\Stats\VolumeComparison;
use App\DTOs\Stats\VolumeHistoryPoint;
use App\DTOs\Stats\VolumeTrendPoint;
use App\DTOs\Stats\WeeklyVolumeTrendPoint;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Les statistiques de volume : tendances par jour, par semaine et par mois, et
 * les comparaisons d'une période à la précédente.
 *
 * Tout ce qui peut s'agréger le fait en base, et chaque résultat est mis en
 * cache : ces chiffres sont recalculés à chaque ouverture de l'écran des
 * statistiques, sur un historique qui ne cesse de grandir.
 */
final class VolumeStatsService
{
    /**
     * La tendance du volume sur les derniers jours.
     *
     * @param  User  $user  L'utilisateur concerné.
     * @param  int  $jours  La profondeur d'historique, en jours.
     * @return array<int, VolumeTrendPoint>
     */
    public function getVolumeTrend(User $user, int $jours = 30): array
    {
        return Cache::remember(
            ClesDeStats::seances($user, "volume_trend.{$jours}"),
            now()->addMinutes(30),
            function () use ($user, $jours): array {
                /*
                 * Le `toBase()` qui etait ici est parti.
                 *
                 * Il se reclamait d'une economie « pour de gros volumes », sur
                 * une requete bornee a `$jours` jours et limitee a trois
                 * colonnes : il n'evitait que l'hydratation de quelques
                 * dizaines de lignes, et il coutait deux entrees de baseline
                 * PHPStan. Eloquent caste `started_at` en Carbon et `name` en
                 * `?string`, ce qui supprime le reparsage a la main.
                 */
                $workouts = $user->workouts()
                    ->where('started_at', '>=', now()->subDays($jours))
                    ->select(['id', 'started_at', 'name', 'workout_volume'])
                    ->orderBy('started_at')
                    ->get();

                $trend = [];
                foreach ($workouts as $row) {
                    $trend[] = new VolumeTrendPoint(
                        $row->started_at->format('d/m'),
                        $row->started_at->format('Y-m-d'),
                        // Le meme repli que WorkoutStatsService:45. Sans lui,
                        // une seance sans nom sortait sous une etiquette VIDE
                        // ici, et sous « Séance » la — memes donnees, deux
                        // reponses.
                        $row->name ?? __('Workout'),
                        $row->workout_volume,
                    );
                }

                return $trend;
            }
        );
    }

    /**
     * Le volume de chaque jour de la semaine en cours, du lundi au dimanche.
     *
     * Les sept jours sortent toujours, y compris ceux sans séance : la courbe
     * doit montrer les creux, pas les sauter.
     *
     * @param  User  $user  L'utilisateur concerné.
     * @return array<int, WeeklyVolumeTrendPoint>
     */
    public function getWeeklyVolumeTrend(User $user): array
    {
        return Cache::remember(
            ClesDeStats::seances($user, 'weekly_volume'),
            now()->addMinutes(10),
            function () use ($user): array {
                $startOfWeek = now()->startOfWeek();
                $endOfWeek = now()->endOfWeek();

                // `toBase()` : la somme sort déjà agrégée, aucun modèle à
                // hydrater derrière.
                $workouts = $user->workouts()
                    ->toBase()
                    ->whereBetween('started_at', [$startOfWeek, $endOfWeek])
                    ->selectRaw('DATE(started_at) as date, SUM(workout_volume) as total_volume')
                    ->groupBy('date')
                    ->get()
                    ->keyBy('date');

                $trend = [];
                for ($i = 0; $i < 7; $i++) {
                    $dateObj = $startOfWeek->copy()->addDays($i);
                    $date = $dateObj->format('Y-m-d');
                    $workoutData = $workouts->get($date);
                    $trend[] = new WeeklyVolumeTrendPoint(
                        $date,
                        ucfirst($dateObj->translatedFormat('D')),
                        $workoutData !== null && is_numeric($workoutData->total_volume) ? (float) $workoutData->total_volume : 0.0,
                    );
                }

                return $trend;
            }
        );
    }

    /**
     * Le volume des dernières séances terminées, une par point.
     *
     * @param  User  $user  L'utilisateur concerné.
     * @param  int  $limit  Le nombre de séances au plus.
     * @return array<int, VolumeHistoryPoint>
     */
    public function getVolumeHistory(User $user, int $limit = 20): array
    {
        return Cache::remember(
            ClesDeStats::seances($user, "volume_history.{$limit}"),
            now()->addMinutes(30),
            function () use ($user, $limit): array {
                /*
                 * Meme raison que dans `getVolumeTrend` : le `toBase()` qui
                 * etait ici portait sur une requete bornee par `limit($limit)`,
                 * donc il n'evitait l'hydratation que de `$limit` lignes, pour
                 * deux entrees de baseline PHPStan.
                 */
                $workouts = $user->workouts()
                    ->whereNotNull('ended_at')
                    ->select(['id', 'started_at', 'name', 'workout_volume'])
                    ->orderBy('started_at')
                    ->limit($limit)
                    ->get();

                $historique = [];
                foreach ($workouts as $row) {
                    $historique[] = new VolumeHistoryPoint(
                        $row->started_at->format('d/m'),
                        $row->workout_volume,
                        // Le meme repli que WorkoutStatsService:45. Sans lui,
                        // une seance sans nom sortait sous une etiquette VIDE
                        // ici, et sous « Séance » la — memes donnees, deux
                        // reponses.
                        $row->name ?? __('Workout'),
                    );
                }

                return $historique;
            }
        );
    }

    /**
     * Le volume du mois en cours contre celui du mois précédent.
     *
     * @param  User  $user  L'utilisateur concerné.
     */
    public function getMonthlyVolumeComparison(User $user): VolumeComparison
    {
        $comparison = $this->calculateComparison(
            $user,
            now()->startOfMonth(),
            now()->subMonth()->startOfMonth(),
            now()->subMonth()->endOfMonth()
        );

        return new VolumeComparison(
            $comparison['current_volume'],
            $comparison['previous_volume'],
            $comparison['difference'],
            $comparison['percentage'],
        );
    }

    /**
     * Le volume de la semaine en cours contre celui de la semaine précédente.
     *
     * @param  User  $user  L'utilisateur concerné.
     */
    public function getWeeklyVolumeComparison(User $user): VolumeComparison
    {
        $weekKey = now()->startOfWeek()->format('Y-W');

        $comparison = Cache::remember(
            ClesDeStats::seances($user, "weekly_volume_comparison.{$weekKey}"),
            now()->addMinutes(10),
            fn (): array => $this->calculateComparison(
                $user,
                now()->startOfWeek(),
                now()->subWeek()->startOfWeek(),
                now()->subWeek()->endOfWeek()
            )
        );

        return new VolumeComparison(
            $comparison['current_volume'],
            $comparison['previous_volume'],
            $comparison['difference'],
            $comparison['percentage'],
        );
    }

    /**
     * Le volume total de chacun des derniers mois.
     *
     * @param  User  $user  L'utilisateur concerné.
     * @param  int  $months  Le nombre de mois couverts.
     * @return array<int, MonthlyVolumePoint>
     */
    public function getMonthlyVolumeHistory(User $user, int $months = 6): array
    {
        return Cache::remember(
            ClesDeStats::seances($user, "monthly_volume_history.{$months}"),
            now()->addMinutes(30),
            function () use ($user, $months): array {
                // Le regroupement et la somme se font en SQL : ramener des mois
                // entiers de séances en PHP pour les additionner ne tiendrait pas
                // sur un long historique. Le format de date dépend du pilote,
                // MySQL et SQLite ne l'écrivant pas pareil.
                $driver = \Illuminate\Support\Facades\DB::getDriverName();
                $monthFormat = $driver === 'sqlite' ? "strftime('%Y-%m', started_at)" : "DATE_FORMAT(started_at, '%Y-%m')";

                $results = $user->workouts()
                    ->toBase()
                    ->where('started_at', '>=', now()->subMonths($months - 1)->startOfMonth())
                    ->selectRaw("{$monthFormat} as month, SUM(workout_volume) as volume")
                    ->groupBy('month')
                    ->pluck('volume', 'month');

                return collect(range($months - 1, 0))
                    ->map(function (int $i) use ($results): MonthlyVolumePoint {
                        $date = now()->subMonths($i);
                        $monthKey = $date->format('Y-m');
                        $sum = $results->get($monthKey) ?? 0.0;

                        /*
                         * Le second repli ne peut pas s'executer : le `??`
                         * ci-dessus garantit deja une valeur numerique. Il reste
                         * parce que `pluck()` rend du non type et que PHPStan
                         * refuse — a raison — un cast pose pour le faire taire.
                         * Ses deux mutants sont donc equivalents, pas non
                         * couverts.
                         *
                         * @pest-mutate-ignore
                         */
                        return new MonthlyVolumePoint(
                            $date->translatedFormat('M'),
                            is_numeric($sum) ? (float) $sum : 0.0,
                        );
                    })
                    ->all();
            }
        );
    }

    /**
     * Compare le volume d'une période à celui de la précédente.
     *
     * @param  User  $user  L'utilisateur concerné.
     * @param  Carbon  $currentStart  Le début de la période courante.
     * @param  Carbon  $prevStart  Le début de la période précédente.
     * @param  Carbon  $prevEnd  La fin de la période précédente.
     * @return array{current_volume: float, previous_volume: float, difference: float, percentage: float|null} Le pourcentage est nul quand il n'y a rien à comparer.
     */
    private function calculateComparison(User $user, Carbon $currentStart, Carbon $prevStart, Carbon $prevEnd): array
    {
        // Les deux sommes tiennent dans une seule requête, par agrégation
        // conditionnelle : les deux périodes se suivent, donc une seule plage
        // les couvre.
        $query = $user->workouts()
            ->toBase()
            ->where('started_at', '>=', $prevStart);

        // Le parametre etait optionnel, avec une branche de repli ou previous_volume
        // valait la somme de toute la plage — donc en incluant la periode courante,
        // ce qui rendait la difference systematiquement negative. Les deux seuls
        // appelants passent $prevEnd, et l'ont toujours passe : la branche etait
        // inatteignable, et le commentaire qui la disait « conserver exactement le
        // comportement d'origine » protegeait du code que personne n'executait.
        $query->selectRaw('
            SUM(CASE WHEN started_at >= ? THEN workout_volume ELSE 0 END) as current_volume,
            SUM(CASE WHEN started_at <= ? THEN workout_volume ELSE 0 END) as previous_volume
        ', [$currentStart, $prevEnd]);

        /** @var \stdClass|null $stats */
        $stats = $query->first();

        $currentVolume = is_numeric($stats?->current_volume) ? (float) $stats->current_volume : 0.0;
        $previousVolume = is_numeric($stats?->previous_volume) ? (float) $stats->previous_volume : 0.0;

        $diff = $currentVolume - $previousVolume;

        /*
         * Null, et non un chiffre, quand il n'y a rien a comparer.
         *
         * Une periode precedente a zero volume ne donne aucune base. La formule
         * renvoyait 100 dans ce cas, et la premiere semaine suivie par un
         * utilisateur s'affichait « +100 % vs sem. passee » : un gain invente
         * contre une semaine qui n'existe pas, d'autant plus credible qu'il
         * ressemble a un vrai chiffre. Mesure : current=500, previous=0,
         * percentage=100.0 (#1388).
         *
         * Zero garde ainsi son sens propre — volume identique d'une periode a
         * l'autre — au lieu d'etre confondu avec l'absence de comparaison.
         */
        $percentage = $previousVolume > 0 ? round($diff / $previousVolume * 100, 1) : null;

        return [
            'current_volume' => $currentVolume,
            'previous_volume' => $previousVolume,
            'difference' => $diff,
            'percentage' => $percentage,
        ];
    }
}
