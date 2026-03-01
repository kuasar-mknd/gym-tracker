<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Stats\FetchStatsOverviewAction;
use App\Models\Exercise;
use App\Services\StatsService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StatsController extends Controller
{
    public function __construct(protected StatsService $statsService)
    {
    }

    /**
     * Display the main statistics dashboard with Deferred Loading (Inertia 2.0).
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
            'volumeTrend' => Inertia::defer(fn () => $this->statsService->getVolumeTrend($user, $days)),
            'muscleDistribution' => Inertia::defer(fn () => $this->statsService->getMuscleDistribution($user, $days)),
            'monthlyComparison' => Inertia::defer(fn () => $this->statsService->getMonthlyVolumeComparison($user)),
            'weightHistory' => Inertia::defer(fn () => $this->statsService->getWeightHistory($user, $days)),
            'bodyFatHistory' => Inertia::defer(fn () => $this->statsService->getBodyFatHistory($user, $days)),
            'durationHistory' => Inertia::defer(fn () => $this->statsService->getDurationHistory($user, 30)),
        ]);
    }

    /**
     * Get 1RM progress for a specific exercise as JSON.
     */
    public function exercise(Exercise $exercise): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'progress' => $this->statsService->getExercise1RMProgress($this->user(), $exercise->id),
        ]);
    }
}
