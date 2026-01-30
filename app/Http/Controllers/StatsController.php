<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Exercise;
use App\Services\StatsService;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class StatsController extends Controller
{
    public function __construct(protected StatsService $statsService)
    {
    }

    /**
     * Display the main statistics dashboard.
     */
    public function index(\Illuminate\Http\Request $request): \Inertia\Response
    {
        $user = $this->user();
        $days = $this->parsePeriod($request->query('period', '30j'));

        // Body metrics and weight history
        $bodyMetrics = $this->statsService->getLatestBodyMetrics($user);
        $weightHistory = $this->statsService->getWeightHistory($user, $days);

        return Inertia::render('Stats/Index', [
            'volumeTrend' => $this->statsService->getVolumeTrend($user, $days),
            'muscleDistribution' => $this->statsService->getMuscleDistribution($user, $days),
            'monthlyComparison' => $this->statsService->getMonthlyVolumeComparison($user),
            'weightHistory' => $weightHistory,
            'bodyFatHistory' => $this->statsService->getBodyFatHistory($user, $days),
            'durationHistory' => $this->statsService->getDurationHistory($user, 30),
            'latestWeight' => $bodyMetrics['latest_weight'],
            'weightChange' => $bodyMetrics['weight_change'],
            'bodyFat' => $bodyMetrics['latest_body_fat'],
            'exercises' => $this->getFilteredExercises($user->id),
            'selectedPeriod' => $request->query('period', '30j'),
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

    /** @return \Illuminate\Database\Eloquent\Collection<int, Exercise> */
    private function getFilteredExercises(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return Cache::remember("exercises_list_{$userId}", 3600, fn () => Exercise::forUser($userId)->orderBy('name')->get());
    }
}
