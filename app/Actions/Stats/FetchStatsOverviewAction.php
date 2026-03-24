<?php

declare(strict_types=1);

namespace App\Actions\Stats;

use App\Models\Exercise;
use App\Models\User;
use App\Services\StatsService;

/**
 * Action responsible for fetching the necessary data to render the stats overview dashboard.
 *
 * It coordinates with the StatsService to retrieve immediate, lightweight statistics
 * like current weight and basic activity counts, and provides parsing for date periods.
 */
class FetchStatsOverviewAction
{
    /**
     * Create a new FetchStatsOverviewAction instance.
     *
     * @param  \App\Services\StatsService  $statsService  The underlying service for retrieving stats.
     */
    public function __construct(protected StatsService $statsService)
    {
    }

    /**
     * Get lightweight stats for immediate display on the dashboard.
     *
     * This method fetches metrics that are fast to calculate, such as the user's
     * latest body measurements and cached exercise list.
     *
     * @param  \App\Models\User  $user  The user to fetch stats for.
     * @param  string  $period  The requested time period (e.g., '30j').
     * @return array<string, mixed> An array of immediate statistics.
     */
    public function getImmediateStats(User $user, string $period): array
    {
        $bodyMetrics = $this->statsService->getLatestBodyMetrics($user);

        return [
            'latestWeight' => $bodyMetrics->latest_weight,
            'weightChange' => $bodyMetrics->weight_change,
            'bodyFat' => $bodyMetrics->latest_body_fat,
            'exercises' => $this->getFilteredExercises($user->id),
            'selectedPeriod' => $period,
        ];
    }

    /**
     * Parse a human-readable period string into an integer number of days.
     *
     * Defaults to 30 days if the provided period string is unrecognized.
     *
     * @param  string  $period  The period string (e.g., '7j', '30j', '90j', '1a').
     * @return int The equivalent number of days.
     */
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

    /**
     * Retrieve the list of available exercises for the user, usually cached.
     *
     * @param  int  $userId  The ID of the user.
     * @return \Illuminate\Database\Eloquent\Collection<int, Exercise> A collection of exercises.
     */
    private function getFilteredExercises(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return Exercise::getCachedForUser($userId);
    }
}
