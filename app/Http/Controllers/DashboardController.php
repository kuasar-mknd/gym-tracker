<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Dashboard\FetchDashboardDataAction;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Controller for the Dashboard landing page.
 *
 * This controller aggregates key user statistics and recent activity
 * to be displayed on the main dashboard. It utilizes Deferred props (Inertia 2.0)
 * to load heavy charts asynchronously, ensuring fast initial page rendering.
 */
class DashboardController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * Aggregates the following data for the authenticated user:
     * - Total workout count (Immediate)
     * - Weekly workout count (Immediate)
     * - Latest body weight measurement (Immediate)
     * - Recent workouts (Immediate)
     * - Recent Personal Records (Immediate)
     * - Active goals (Immediate)
     *
     * Deferred data (loaded asynchronously):
     * - Weekly volume stats
     * - Volume trends
     * - Duration distribution
     *
     * @param  \Illuminate\Http\Request  $request  The incoming HTTP request.
     * @return \Inertia\Response The Inertia response rendering the 'Dashboard' page.
     */
    public function __invoke(Request $request, FetchDashboardDataAction $fetchDashboardData): \Inertia\Response
    {
        $user = $this->user();

        // Fetch immediate data (fast queries)
        $data = $fetchDashboardData->getImmediateStats($user);

        return Inertia::render('Dashboard', [
            ...$data,
            // Defer heavy chart data
            'weeklyVolume' => Inertia::defer(fn () => $fetchDashboardData->getWeeklyVolumeStats($user)['current_week_volume']),
            'volumeChange' => Inertia::defer(fn () => $fetchDashboardData->getWeeklyVolumeStats($user)['percentage']),
            'weeklyVolumeTrend' => Inertia::defer(fn (): array => $fetchDashboardData->getWeeklyVolumeTrend($user)),
            'volumeTrend' => Inertia::defer(fn (): array => $fetchDashboardData->getVolumeTrend($user)),
            'durationDistribution' => Inertia::defer(fn (): array => $fetchDashboardData->getDurationDistribution($user)),
        ]);
    }
}
