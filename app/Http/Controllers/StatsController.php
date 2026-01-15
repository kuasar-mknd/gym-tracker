<?php

namespace App\Http\Controllers;

use App\Models\Exercise;
use App\Services\StatsService;
use Illuminate\Support\Facades\Cache;
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

        // NITRO FIX: Cache exercises list for 1 hour, scoped to user for security & performance
        $exercises = Cache::remember("exercises_list_{$user->id}", 3600, function () use ($user) {
            return Exercise::whereNull('user_id')
                ->orWhere('user_id', $user->id)
                ->orderBy('name')
                ->get();
        });

        return Inertia::render('Stats/Index', [
            'volumeTrend' => $this->statsService->getVolumeTrend($user),
            'muscleDistribution' => $this->statsService->getMuscleDistribution($user),
            'monthlyComparison' => $this->statsService->getMonthlyVolumeComparison($user),
            'exercises' => $exercises,
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
