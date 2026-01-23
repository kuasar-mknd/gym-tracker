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
    public function __invoke(Request $request, FetchDashboardDataAction $fetchDashboardData): \Inertia\Response
    {
        $data = $fetchDashboardData->execute($this->user());

        return Inertia::render('Dashboard', $data);
    }
}
