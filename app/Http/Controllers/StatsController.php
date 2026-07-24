<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Stats\GetStatsDashboardAction;
use App\Models\Exercise;
use App\Services\StatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
     * @param  StatsService  $statsService  Service for retrieving stats data.
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
     * @param  Request  $request  The incoming HTTP request.
     * @param  GetStatsDashboardAction  $getStatsDashboard  Action to fetch overview stats.
     * @return Response The Inertia response rendering the 'Stats/Index' page.
     */
    public function index(Request $request, GetStatsDashboardAction $getStatsDashboard): Response
    {
        $data = $getStatsDashboard->execute($this->user(), $request);

        // ⚡ Bolt: PERFORMANCE OPTIMIZATION
        // Consolidate deferred props to reduce the number of async requests and backend executions.
        // This reduces HTTP overhead (1 XHR instead of 2) and ensures related visualizations
        // appear together for a better user experience.
        /** @var callable(): mixed $deferredDataCallable */
        $deferredDataCallable = $data['deferredData'];
        $data['deferredData'] = Inertia::defer($deferredDataCallable);

        return Inertia::render('Stats/Index', $data);
    }

    /**
     * Get 1RM (One Rep Max) progress for a specific exercise as JSON.
     *
     * @param  Exercise  $exercise  The exercise to get progress for.
     * @return JsonResponse JSON response containing the progress data.
     */
    public function exercise(Exercise $exercise): JsonResponse
    {
        $this->authorize('view', $exercise);

        return response()->json([
            'progress' => $this->statsService->getExercise1RMProgress($this->user(), $exercise->id),
        ]);
    }
}
