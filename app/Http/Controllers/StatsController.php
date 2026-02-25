<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Stats\FetchStatsOverviewAction;
use App\Models\Exercise;
use App\Services\StatsService;
use Inertia\Inertia;

class StatsController extends Controller
{
    public function __construct(protected StatsService $statsService)
    {
    }

    /**
     * Display the main statistics dashboard.
     */
    public function index(\Illuminate\Http\Request $request, FetchStatsOverviewAction $fetchStatsOverview): \Inertia\Response
    {
        return Inertia::render('Stats/Index', $fetchStatsOverview->execute(
            $this->user(),
            $request->query('period', '30j')
        ));
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
