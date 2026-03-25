<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Stats\FetchStatsOverviewAction;
use App\Models\Exercise;
use App\Services\StatsService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Controller for displaying user statistics dashboards.
 *
 * This controller aggregates and formats data related to user workouts,
 * volume, and body measurements to be presented on the frontend.
 */
class StatsController extends Controller
{
    /**
     * Create a new StatsController instance.
     *
     * @param  \App\Services\StatsService  $statsService  Service for retrieving stats data.
     */
    public function __construct(protected StatsService $statsService)
    {
    }

    /**
     * Display the main statistics dashboard with Deferred Loading (Inertia 2.0).
     *
     * Retrieves immediate data for fast initial rendering and uses Inertia's
     * deferred loading for heavier analytical queries (like trends and distributions).
     *
     * @param  \Illuminate\Http\Request  $request  The incoming HTTP request.
     * @param  \App\Actions\Stats\FetchStatsOverviewAction  $fetchStatsOverview  Action to fetch overview stats.
     * @return \Inertia\Response The Inertia response rendering the 'Stats/Index' page.
     */
    public function index(Request $request, FetchStatsOverviewAction $fetchStatsOverview): \Inertia\Response
    {
        $user = $this->user();
        $period = $request->query('period', '30j');
        $days = $fetchStatsOverview->parsePeriod($period);

        // Immediate data
        $immediateData = $fetchStatsOverview->getImmediateStats($user, $period);

        return Inertia::render('Stats/Index', [
            ...$immediateData,
            // ⚡ Bolt: Consolidate deferred props to reduce the number of async requests.
            // Performance Stats: volumeTrend, muscleDistribution, monthlyComparison, durationHistory
            'performanceStats' => Inertia::defer(fn (): array => $this->statsService->getPerformanceOverview($user, $days)),
            // Body Stats: weightHistory, bodyFatHistory
            'bodyStats' => Inertia::defer(fn (): array => $this->statsService->getBodyProgressOverview($user, $days)),
        ]);
    }

    /**
     * Get 1RM (One Rep Max) progress for a specific exercise as JSON.
     *
     * @param  \App\Models\Exercise  $exercise  The exercise to get progress for.
     * @return \Illuminate\Http\JsonResponse JSON response containing the progress data.
     */
    public function exercise(Exercise $exercise): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'progress' => $this->statsService->getExercise1RMProgress($this->user(), $exercise->id),
        ]);
    }
}
