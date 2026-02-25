<?php

declare(strict_types=1);

namespace App\Actions\Stats;

use App\Models\Exercise;
use App\Models\User;
use App\Services\StatsService;

class FetchStatsOverviewAction
{
    public function __construct(protected StatsService $statsService)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(User $user, string $period): array
    {
        $days = $this->parsePeriod($period);

        // Body metrics and weight history
        $bodyMetrics = $this->statsService->getLatestBodyMetrics($user);
        $weightHistory = $this->statsService->getWeightHistory($user, $days);

        return [
            'volumeTrend' => $this->statsService->getVolumeTrend($user, $days),
            'muscleDistribution' => $this->statsService->getMuscleDistribution($user, $days),
            'monthlyComparison' => $this->statsService->getMonthlyVolumeComparison($user),
            'weightHistory' => $weightHistory,
            'bodyFatHistory' => $this->statsService->getBodyFatHistory($user, $days),
            'durationHistory' => $this->statsService->getDurationHistory($user, 30),
            'latestWeight' => $bodyMetrics['latest_weight'],
            'weightChange' => $bodyMetrics['weight_change'],
            'bodyFat' => $bodyMetrics['latest_body_fat'],
            'exercises' => $this->getFilteredExercises($user->id),
            'selectedPeriod' => $period,
        ];
    }

    private function parsePeriod(string $period): int
    {
        return match ($period) {
            '7j' => 7,
            '30j' => 30,
            '90j' => 90,
            '1a' => 365,
            default => 30,
        };
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Exercise> */
    private function getFilteredExercises(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return Exercise::getCachedForUser($userId);
    }
}
