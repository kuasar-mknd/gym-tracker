<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Models\User;
use App\Services\Stats\BodyStatsService;
use App\Services\Stats\ClesDeStats;
use App\Services\Stats\VolumeStatsService;
use App\Services\Stats\WorkoutStatsService;
use Illuminate\Support\Carbon;

/**
 * Ce que le tableau de bord affiche.
 *
 * Le léger part avec la page ; les analyses coûteuses attendent une prop
 * différée.
 */
final class FetchDashboardDataAction
{
    /**
     * @param  \App\Services\Stats\BodyStatsService  $bodyStats  Les dernières mesures corporelles.
     * @param  \App\Services\Stats\VolumeStatsService  $volumeStats  Tendance et comparaison du volume hebdomadaire.
     * @param  \App\Services\Stats\WorkoutStatsService  $workoutStats  Répartitions des séances.
     */
    public function __construct(
        protected BodyStatsService $bodyStats,
        protected VolumeStatsService $volumeStats,
        protected WorkoutStatsService $workoutStats
    ) {
    }

    /**
     * Les données immédiates : des lectures légères, ou d'une seule ligne,
     * tenables au premier rendu de la page.
     *
     * @return array{
     *     latestWeight: float|string|null,
     *     recentWorkouts: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Workout>,
     *     recentPRs: \Illuminate\Database\Eloquent\Collection<int, \App\Models\PersonalRecord>,
     *     activeGoals: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Goal>
     * }
     */
    public function chiffresImmediats(User $user): array
    {
        // Les mesures passent par le cache : sinon la base est lue à chaque
        // ouverture du tableau de bord.
        $latestMetrics = $this->bodyStats->getLatestBodyMetrics($user);

        return [
            // `workoutsCount` et `thisWeekCount` sont partis : rien ne les
            // lisait, et ils coûtaient deux requêtes à chaque ouverture.
            'latestWeight' => $latestMetrics->latest_weight ?? null,
            'recentWorkouts' => $this->getRecentWorkouts($user),
            'recentPRs' => $this->getRecentPRs($user),
            'activeGoals' => $this->getActiveGoals($user),
        ];
    }

    /**
     * La carte hebdomadaire : le total de la semaine, sa comparaison à la
     * précédente et la courbe de ses sept jours.
     *
     * Les trois se tirent d'une seule lecture de l'horloge : le total et la
     * courbe lisaient chacun l'heure, et quand lundi commençait entre les
     * deux, la carte annonçait le total de la semaine qui s'achevait au-dessus
     * de la courbe, vide, de celle qui commençait (#2033).
     *
     * @param  Carbon|null  $maintenant  L'instant de l'opération ; à défaut, l'horloge.
     * @return array{stats: array{current_week_volume: float, percentage: float|null}, trend: array<int, \App\DTOs\Stats\WeeklyVolumeTrendPoint>}
     */
    public function getWeeklyVolumeData(User $user, ?Carbon $maintenant = null): array
    {
        $maintenant ??= now();
        $stats = $this->volumeStats->getWeeklyVolumeComparison($user, $maintenant);

        return [
            'stats' => [
                'current_week_volume' => $stats->current_volume,
                'percentage' => $stats->percentage,
            ],
            'trend' => $this->volumeStats->getWeeklyVolumeTrend($user, $maintenant),
        ];
    }

    /**
     * Les analyses du tableau de bord, réunies en une seule prop différée.
     *
     * Séparées, elles faisaient deux requêtes XHR et occupaient deux clefs de
     * cache ; ensemble, une seule.
     *
     * La clef porte la semaine de la carte hebdomadaire qu'elle garde, tirée du
     * même instant que la carte : sans elle, le lundi juste après minuit
     * servait encore, jusqu'à l'expiration, la semaine de la veille comme la
     * semaine en cours (#2033), comme le premier du mois servait la fenêtre de
     * la veille avant que le mois n'entre dans les clefs mensuelles (#1955).
     *
     * @return array{
     *     weeklyVolume: array{stats: array{current_week_volume: float, percentage: float|null}, trend: array<int, \App\DTOs\Stats\WeeklyVolumeTrendPoint>},
     *     workoutDistributions: array{duration: array<int, \App\DTOs\Stats\DistributionStat>, time_of_day: array<int, \App\DTOs\Stats\DistributionStat>}
     * }
     */
    public function getAnalyticalStats(User $user): array
    {
        $maintenant = now();

        return \Illuminate\Support\Facades\Cache::remember(
            ClesDeStats::seances($user, 'dashboard_analytical.'.$maintenant->copy()->startOfWeek()->format('Y-W')),
            $maintenant->copy()->addMinutes(10),
            fn (): array => [
                'weeklyVolume' => $this->getWeeklyVolumeData($user, $maintenant),
                'workoutDistributions' => $this->repartitionsDesSeances($user),
            ]
        );
    }

    /**
     * @return array{duration: array<int, \App\DTOs\Stats\DistributionStat>, time_of_day: array<int, \App\DTOs\Stats\DistributionStat>}
     */
    public function repartitionsDesSeances(User $user): array
    {
        return $this->workoutStats->repartitionsDesSeances($user, 90);
    }

    /**
     * Les derniers records personnels : deux, soit exactement ce que le tableau
     * de bord affiche.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\PersonalRecord>
     */
    private function getRecentPRs(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $user->personalRecords()
            ->with('exercise')
            ->latest('achieved_at')
            ->take(2)
            ->get();
    }

    /**
     * Les objectifs en cours : deux, soit exactement ce que le tableau de bord
     * affiche.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Goal>
     */
    private function getActiveGoals(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $user->goals()
            ->with('exercise')
            ->whereNull('completed_at')
            ->latest()
            ->take(2)
            ->get()
            ->append(['unit']);
    }

    /**
     * Les dernières séances : trois, soit ce que la mise en page prévoit.
     *
     * `withCount('workoutLines')` et non `with()` : la carte n'affiche que le
     * nombre de lignes, hydrater les collections entières ne servirait à rien.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Workout>
     */
    private function getRecentWorkouts(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $user->workouts()
            ->withCount('workoutLines')
            ->latest('started_at')
            ->limit(3)
            ->get();
    }
}
