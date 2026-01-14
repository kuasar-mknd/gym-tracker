<?php

namespace App\Http\Controllers;

use App\Models\Exercise;
use App\Services\StatsService;
use Inertia\Inertia;

class StatsController extends Controller
{
    public function __construct(protected StatsService $statsService) {}

    /**
     * Display the main statistics dashboard.
     */
    public function index(): \Inertia\Response
    {
        $user = auth()->user();

        return Inertia::render('Stats/Index', [
            'volumeTrend' => $this->statsService->getVolumeTrend($user),
            'muscleDistribution' => $this->statsService->getMuscleDistribution($user),
            'monthlyComparison' => $this->statsService->getMonthlyVolumeComparison($user),
            'exercises' => Exercise::orderBy('name')->get(),
        ]);
    }

    /**
     * Get 1RM progress for a specific exercise as JSON.
     */
    public function exercise(Exercise $exercise): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'progress' => $this->statsService->getExercise1RMProgress(auth()->user(), $exercise->id),
        ]);
    }
}
