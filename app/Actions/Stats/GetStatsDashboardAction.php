<?php

declare(strict_types=1);

namespace App\Actions\Stats;

use App\Models\User;
use App\Services\StatsService;
use Illuminate\Http\Request;

/**
 * Action responsible for compiling the full stats dashboard data.
 *
 * It aggregates immediate stats and prepares deferred data loading
 * for heavy analytical queries (like performance trends).
 */
class GetStatsDashboardAction
{
    /**
     * Create a new GetStatsDashboardAction instance.
     */
    public function __construct(
        protected FetchStatsOverviewAction $fetchStatsOverview,
        protected StatsService $statsService
    ) {
    }

    /**
     * Execute the action to compile dashboard data.
     *
     * @param  \App\Models\User  $user  The authenticated user.
     * @param  \Illuminate\Http\Request  $request  The incoming HTTP request.
     * @return array<string, mixed> Array of data for the Inertia response.
     */
    public function execute(User $user, Request $request): array
    {
        $period = $request->query('period', '30j');
        $days = $this->fetchStatsOverview->parsePeriod($period);

        // Immediate data
        $immediateData = $this->fetchStatsOverview->getImmediateStats($user, $period);

        return [
            ...$immediateData,
            // Return a closure so the presentation layer (Controller) can wrap it as needed (e.g. Inertia::defer)
            'deferredData' => fn (): array => [
                'performance' => $this->statsService->getPerformanceOverview($user, $days),
                'body' => $this->statsService->getBodyProgressOverview($user, $days),
            ],
        ];
    }
}
