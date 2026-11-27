<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Models\User;
use App\Services\Stats\ClesDeStats;
use App\Services\Stats\VolumeStatsService;
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
     * @param  \App\Services\Stats\VolumeStatsService  $volumeStats  Tendance et comparaison du volume hebdomadaire.
     */
    public function __construct(
        protected VolumeStatsService $volumeStats
    ) {
    }

    /**
     * Les données immédiates : des lectures légères, ou d'une seule ligne,
     * tenables au premier rendu de la page.
     *
     * Chacune est une fermeture, qu'Inertia ne résout que si sa prop part dans
     * la réponse : la requête de la prop différée `analyticalStats`, qui
     * rejoue le contrôleur, relisait sinon la pesée, les séances, les records
     * et les objectifs pour les jeter aussitôt (#2148, étape 3a). Aucune ne tire
     * de l'horloge ce qu'elle rend : seule l'échéance de l'entrée de la pesée en
     * dépend, lue par le service (`.ai/rules/horloge.md`).
     *
     * @return array{
     *     recentWorkouts: \Closure(): \Illuminate\Database\Eloquent\Collection<int, \App\Models\Workout>,
     *     recentPRs: \Closure(): \Illuminate\Database\Eloquent\Collection<int, \App\Models\PersonalRecord>,
     *     activeGoals: \Closure(): \Illuminate\Database\Eloquent\Collection<int, \App\Models\Goal>
     * }
     */
    public function chiffresImmediats(User $user): array
    {
        return [
            'recentWorkouts' => fn (): \Illuminate\Database\Eloquent\Collection => $this->getRecentWorkouts($user),
            'recentPRs' => fn (): \Illuminate\Database\Eloquent\Collection => $this->getRecentPRs($user),
            'activeGoals' => fn (): \Illuminate\Database\Eloquent\Collection => $this->getActiveGoals($user),
        ];
    }

    /**
     * La carte hebdomadaire : le total de la semaine, le nombre de ses séances,
     * sa comparaison à la précédente à la même date et la courbe de ses sept
     * jours.
     *
     * Tout se tire d'une seule lecture de l'horloge : le total et la
     * courbe lisaient chacun l'heure, et quand lundi commençait entre les
     * deux, la carte annonçait le total de la semaine qui s'achevait au-dessus
     * de la courbe, vide, de celle qui commençait (#2033).
     *
     * @param  Carbon|null  $maintenant  L'instant de l'opération ; à défaut, l'horloge.
     * @return array{stats: array{current_week_volume: float, percentage: float|null, sessions_count: int}, trend: array<int, \App\DTOs\Stats\WeeklyVolumeTrendPoint>}
     */
    public function getWeeklyVolumeData(User $user, ?Carbon $maintenant = null): array
    {
        $maintenant ??= now();
        $stats = $this->volumeStats->getWeeklyVolumeComparison($user, $maintenant);

        return [
            'stats' => [
                'current_week_volume' => $stats->current_volume,
                'percentage' => $stats->percentage,
                'sessions_count' => $stats->sessions_count ?? 0,
            ],
            'trend' => $this->volumeStats->getWeeklyVolumeTrend($user, $maintenant),
        ];
    }

    /**
     * Les analyses du tableau de bord, réunies en une seule prop différée : la
     * carte de la semaine, seule analyse que l'accueil garde.
     *
     * La clef porte la semaine de la carte hebdomadaire qu'elle garde, tirée du
     * même instant que la carte : sans elle, le lundi juste après minuit
     * servait encore, jusqu'à l'expiration, la semaine de la veille comme la
     * semaine en cours (#2033), comme le premier du mois servait la fenêtre de
     * la veille avant que le mois n'entre dans les clefs mensuelles (#1955).
     *
     * @return array{
     *     weeklyVolume: array{stats: array{current_week_volume: float, percentage: float|null, sessions_count: int}, trend: array<int, \App\DTOs\Stats\WeeklyVolumeTrendPoint>}
     * }
     */
    public function getAnalyticalStats(User $user): array
    {
        $maintenant = now();

        return \Illuminate\Support\Facades\Cache::remember(
            ClesDeStats::seances($user, 'dashboard_weekly_card.'.$maintenant->copy()->startOfWeek()->format('Y-W')),
            $maintenant->copy()->addMinutes(10),
            fn (): array => [
                'weeklyVolume' => $this->getWeeklyVolumeData($user, $maintenant),
            ]
        );
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
            ->latest('id')
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
