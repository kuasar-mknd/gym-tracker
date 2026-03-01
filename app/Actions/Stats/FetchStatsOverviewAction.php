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
     * Get lightweight stats for immediate display.
     *
     * @return array<string, mixed>
     */
    public function getImmediateStats(User $user, string $period): array
    {
        $bodyMetrics = $this->statsService->getLatestBodyMetrics($user);

        return [
            'latestWeight' => $bodyMetrics['latest_weight'],
            'weightChange' => $bodyMetrics['weight_change'],
            'bodyFat' => $bodyMetrics['latest_body_fat'],
            'exercises' => $this->getFilteredExercises($user->id),
            'selectedPeriod' => $period,
        ];
    }

    public function parsePeriod(string $period): int
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
