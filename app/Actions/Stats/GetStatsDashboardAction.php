<?php

declare(strict_types=1);

namespace App\Actions\Stats;

use App\Models\User;
use App\Services\Diagnostic\ValeurLue;
use App\Services\Stats\ClesDeStats;
use App\Services\Stats\ExerciseStatsService;
use App\Services\Stats\VolumeStatsService;
use App\Services\Stats\WorkoutStatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class GetStatsDashboardAction
{
    public function __construct(
        protected FetchStatsOverviewAction $fetchStatsOverview,
        protected VolumeStatsService $volumeStats,
        protected ExerciseStatsService $exerciseStats,
        protected WorkoutStatsService $workoutStats,
        protected FetchRegulariteAction $fetchRegularite
    ) {
    }

    /**
     * La période que la page affiche quand la requête n'en demande aucune
     * qui se lise.
     */
    public const string PERIODE_PAR_DEFAUT = '30j';

    /**
     * Les props de la page : les chiffres immédiats, en fermetures que la
     * requête de la prop différée ne résout pas (#2148, étape 3a), et la
     * fermeture de la prop différée, que le contrôleur passe à
     * `Inertia::defer()`.
     *
     * @return array{
     *     exercises: \Closure(): \Illuminate\Database\Eloquent\Collection<int, \App\Models\Exercise>,
     *     selectedPeriod: string,
     *     deferredData: \Closure(): array{
     *         aucuneSeanceFaite: bool,
     *         performance: array<string, mixed>,
     *         volumeMensuel: array<int, \App\DTOs\Stats\MonthlyVolumePoint>,
     *         regularite: array{
     *             monthly_frequency: \Illuminate\Support\Collection<int, array{month: string, count: int}>,
     *             day_of_week_frequency: \Illuminate\Support\Collection<int, array{day: string, count: int}>
     *         }
     *     }
     * }
     */
    public function execute(User $user, Request $request): array
    {
        $period = self::periodeDemandee($request);
        $jours = $this->fetchStatsOverview->parsePeriod($period);

        return [
            ...$this->fetchStatsOverview->chiffresImmediats($user, $period),
            'deferredData' => fn (): array => $this->donneesDifferees($user, $jours),
        ];
    }

    /**
     * Le groupe différé de la page : si le compte n'a fait aucune séance
     * (`User::seancesFaites()`, de n'importe quelle ancienneté : la page montre
     * alors un seul état vide), la vue « performance », le volume des six
     * derniers mois et la régularité (séances par mois et par jour). Les deux
     * dernières suivent le mois calendaire, pas la période choisie, et
     * reçoivent le même instant, lu une fois (#2033).
     *
     * @return array{
     *     aucuneSeanceFaite: bool,
     *     performance: array<string, mixed>,
     *     volumeMensuel: array<int, \App\DTOs\Stats\MonthlyVolumePoint>,
     *     regularite: array{
     *         monthly_frequency: \Illuminate\Support\Collection<int, array{month: string, count: int}>,
     *         day_of_week_frequency: \Illuminate\Support\Collection<int, array{day: string, count: int}>
     *     }
     * }
     */
    private function donneesDifferees(User $user, int $jours): array
    {
        $maintenant = now();

        return [
            'aucuneSeanceFaite' => ! $user->seancesFaites()->exists(),
            'performance' => $this->performanceOverview($user, $jours, $maintenant),
            'volumeMensuel' => $this->volumeStats->getMonthlyVolumeHistory($user, 6, $maintenant),
            'regularite' => $this->fetchRegularite->execute($user, $maintenant),
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
     * @param  Carbon|null  $maintenant  L'instant de l'opération, quand la page en affiche d'autres à côté ; à défaut, l'horloge.
     * @return array<string, mixed>
     */
    public function performanceOverview(User $user, int $jours = 30, ?Carbon $maintenant = null): array
    {
        $maintenant ??= now();
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
