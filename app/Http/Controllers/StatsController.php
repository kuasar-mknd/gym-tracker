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
    public function index(\Illuminate\Http\Request $request): \Inertia\Response
    {
        $user = auth()->user();
        $days = $this->parsePeriod($request->query('period', '30j'));

        // NITRO FIX: Cache exercises list for 1 hour
        // Security: Filter exercises by user to prevent information disclosure
        $userId = auth()->id();
        $exercises = Cache::remember("exercises_list_{$userId}", 3600, function () use ($userId) {
            return Exercise::query()
                ->whereNull('user_id')
                ->orWhere('user_id', $userId)
                ->orderBy('name')
                ->get();
        });

        // Body metrics and weight history
        $bodyMetrics = $this->statsService->getLatestBodyMetrics($user);
        $weightHistory = $this->statsService->getWeightHistory($user, $days);
        $bodyFatHistory = $this->statsService->getBodyFatHistory($user, $days);

        return Inertia::render('Stats/Index', [
            'volumeTrend' => $this->statsService->getVolumeTrend($user, $days),
            'muscleDistribution' => $this->statsService->getMuscleDistribution($user, $days),
            'monthlyComparison' => $this->statsService->getMonthlyVolumeComparison($user),
            'weightHistory' => $weightHistory,
            'bodyFatHistory' => $bodyFatHistory,
            'latestWeight' => $bodyMetrics['latest_weight'],
            'weightChange' => $bodyMetrics['weight_change'],
            'bodyFat' => $bodyMetrics['latest_body_fat'],
            'exercises' => $exercises,
            'selectedPeriod' => $request->query('period', '30j'),
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

    protected function parsePeriod(string $period): int
    {
        return match ($period) {
            '7j' => 7,
            '30j' => 30,
            '90j' => 90,
            '1a' => 365,
            default => 30,
        };
    }
}
