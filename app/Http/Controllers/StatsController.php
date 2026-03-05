<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Stats\FetchStatsOverviewAction;
use App\Models\Exercise;
use App\Services\StatsService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Controller for rendering the User Statistics dashboard.
 *
 * This controller handles fetching and preparing both immediate and
 * deferred statistics data for the user's dashboard view.
 */
class StatsController extends Controller
{
    /**
     * Create a new StatsController instance.
     *
     * @param  \App\Services\StatsService  $statsService  Service for statistics calculations.
     */
    public function __construct(protected StatsService $statsService)
    {
    }

    /**
     * Display the main statistics dashboard with Deferred Loading (Inertia 2.0).
     *
     * @param  \Illuminate\Http\Request  $request  The incoming HTTP request.
     * @param  \App\Actions\Stats\FetchStatsOverviewAction  $fetchStatsOverview  Action to fetch overview data.
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
            // Defer heavy data
            'volumeTrend' => Inertia::defer(fn (): array => $this->statsService->getVolumeTrend($user, $days)),
            'muscleDistribution' => Inertia::defer(fn (): array => $this->statsService->getMuscleDistribution($user, $days)),
            'monthlyComparison' => Inertia::defer(fn (): array => $this->statsService->getMonthlyVolumeComparison($user)),
            'weightHistory' => Inertia::defer(fn (): array => $this->statsService->getWeightHistory($user, $days)),
            'bodyFatHistory' => Inertia::defer(fn (): array => $this->statsService->getBodyFatHistory($user, $days)),
            'durationHistory' => Inertia::defer(fn (): array => $this->statsService->getDurationHistory($user, 30)),
        ]);
    }

    /**
     * Get 1RM progress for a specific exercise as JSON.
     *
     * Retrieves the One Rep Max (1RM) historical progress for a given exercise.
     *
     * @param  \App\Models\Exercise  $exercise  The exercise to get progress for.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the 1RM progress data.
     */
    public function exercise(Exercise $exercise): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'progress' => $this->statsService->getExercise1RMProgress($this->user(), $exercise->id),
        ]);
    }
}
