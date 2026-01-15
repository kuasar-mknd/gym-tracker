<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

/**
 * Controller for the Dashboard landing page.
 *
 * This controller aggregates key user statistics and recent activity
 * to be displayed on the main dashboard. It utilizes caching to optimize
 * performance for heavy aggregations.
 */
class DashboardController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * Aggregates the following data for the authenticated user:
     * - Total workout count
     * - Weekly workout count
     * - Latest body weight measurement
     * - Recent workouts (last 5) with exercises and sets
     * - Recent Personal Records (last 5)
     * - Active goals (in progress, limit 3)
     *
     * The aggregated data is cached for 10 minutes per user to reduce database load.
     *
     * @param  \Illuminate\Http\Request  $request  The incoming HTTP request.
     * @return \Inertia\Response The Inertia response rendering the 'Dashboard' page with the aggregated data.
     */
    public function __invoke(Request $request)
    {
        $user = $request->user();

        // Cache dashboard data for 10 minutes
        $data = Cache::remember("dashboard_data_{$user->id}", 600, function () use ($user) {
            $workoutsCount = $user->workouts()->count();

            $startOfWeek = now()->startOfWeek();
            $thisWeekCount = $user->workouts()
                ->where('started_at', '>=', $startOfWeek)
                ->count();

            $latestMeasurement = $user->bodyMeasurements()->latest('measured_at')->first();

            $recentWorkouts = $user->workouts()
                ->with('workoutLines.exercise', 'workoutLines.sets')
                ->latest('started_at')
                ->limit(5)
                ->get();

            $recentPRs = $user->personalRecords()
                ->with('exercise')
                ->latest('achieved_at')
                ->take(5)
                ->get();

            $activeGoals = $user->goals()
                ->with('exercise')
                ->whereNull('completed_at')
                ->latest()
                ->take(3)
                ->get()
                ->append(['progress', 'unit']);

            return [
                'workoutsCount' => $workoutsCount,
                'thisWeekCount' => $thisWeekCount,
                'latestWeight' => $latestMeasurement?->weight,
                'recentWorkouts' => $recentWorkouts,
                'recentPRs' => $recentPRs,
                'activeGoals' => $activeGoals,
            ];
        });

        return Inertia::render('Dashboard', $data);
    }
}
