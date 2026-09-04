<?php

declare(strict_types=1);

namespace App\Actions\Stats;

use App\Models\User;
use App\Services\Stats\BodyStatsService;
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
     * @return array<string, mixed>
     */
    public function execute(User $user, Request $request): array
    {
        $period = $request->query('period', '30j');
        $days = $this->fetchStatsOverview->parsePeriod($period);

        $immediateData = $this->fetchStatsOverview->getImmediateStats($user, $period);

        return [
            ...$immediateData,
            'deferredData' => fn (): array => [
                'performance' => $this->performanceOverview($user, $days),
                'body' => $this->bodyStats->getBodyProgressOverview($user, $days),
            ],
        ];
    }

    /**
     * La vue « performance » du tableau de bord, composée des quatre séries
     * qu'elle affiche et gardée une demi-heure sous une clef que
     * StatsCacheManager sait oublier.
     *
     * @return array<string, mixed>
     */
    public function performanceOverview(User $user, int $days = 30): array
    {
        return Cache::remember(
            "stats.performance_overview.{$user->id}.{$days}",
            now()->addMinutes(30),
            fn (): array => [
                'volumeTrend' => $this->volumeStats->getVolumeTrend($user, $days),
                'muscleDistribution' => $this->exerciseStats->getMuscleDistribution($user, $days),
                'monthlyComparison' => $this->volumeStats->getMonthlyVolumeComparison($user),
                'durationHistory' => $this->workoutStats->getDurationHistory($user, 30),
            ]
        );
    }
}
