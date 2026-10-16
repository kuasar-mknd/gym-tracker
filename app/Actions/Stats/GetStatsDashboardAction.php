<?php

declare(strict_types=1);

namespace App\Actions\Stats;

use App\Models\User;
use App\Services\Diagnostic\ValeurLue;
use App\Services\Stats\BodyStatsService;
use App\Services\Stats\ClesDeStats;
use App\Services\Stats\ExerciseStatsService;
use App\Services\Stats\VolumeStatsService;
use App\Services\Stats\WorkoutStatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class GetStatsDashboardAction
{
    public function __construct(
        protected FetchStatsOverviewAction $fetchStatsOverview,
        protected VolumeStatsService $volumeStats,
        protected ExerciseStatsService $exerciseStats,
        protected WorkoutStatsService $workoutStats,
        protected BodyStatsService $bodyStats
    ) {
    }

    /**
     * La période que la page affiche quand la requête n'en demande aucune
     * qui se lise.
     */
    public const string PERIODE_PAR_DEFAUT = '30j';

    /**
     * @return array<string, mixed>
     */
    public function execute(User $user, Request $request): array
    {
        $period = self::periodeDemandee($request);
        $jours = $this->fetchStatsOverview->parsePeriod($period);

        $immediateData = $this->fetchStatsOverview->chiffresImmediats($user, $period);

        return [
            ...$immediateData,
            'deferredData' => fn (): array => [
                'performance' => $this->performanceOverview($user, $jours),
                'body' => $this->bodyStats->getBodyProgressOverview($user, $jours),
            ],
        ];
    }

    /**
     * La période de la chaîne de requête, si c'est une chaîne non vide :
     * telle qu'elle arrive, période inconnue comprise, que `parsePeriod()`
     * compte pour trente jours et que la page recopie. Toute autre forme
     * (`?period[]=7j`, `?period=`, que `ConvertEmptyStringsToNull` rend nul)
     * donne la période par défaut : un tableau atteignait
     * `parsePeriod(string)` et la page répondait 500 (signalé dans #2071).
     */
    private static function periodeDemandee(Request $request): string
    {
        $periode = ValeurLue::texte($request->query('period'));

        return $periode !== '' ? $periode : self::PERIODE_PAR_DEFAUT;
    }

    /**
     * La vue « performance » du tableau de bord, composée des quatre séries
     * qu'elle affiche et gardée une demi-heure sous une clef que
     * StatsCacheManager sait oublier.
     *
     * La clef porte le mois courant, parce que la vue contient la comparaison
     * mensuelle : le premier du mois, elle servait sinon la comparaison de la
     * veille jusqu'à l'expiration de l'entrée (#1955). Ce mois et la fenêtre
     * de la comparaison viennent de la même lecture de l'horloge : relue par
     * le service, la comparaison pouvait tomber dans le mois suivant, et
     * l'entrée rangée sous septembre gardait celle d'octobre (#2033).
     *
     * @return array<string, mixed>
     */
    public function performanceOverview(User $user, int $jours = 30): array
    {
        $maintenant = now();
        $mois = $maintenant->format('Y-m');

        return Cache::remember(
            ClesDeStats::seances($user, "performance_overview.{$jours}.{$mois}"),
            $maintenant->copy()->addMinutes(30),
            fn (): array => [
                'volumeTrend' => $this->volumeStats->getVolumeTrend($user, $jours),
                'muscleDistribution' => $this->exerciseStats->getMuscleDistribution($user, $jours),
                'monthlyComparison' => $this->volumeStats->getMonthlyVolumeComparison($user, $maintenant),
                'durationHistory' => $this->workoutStats->getDurationHistory($user, 30),
            ]
        );
    }
}
