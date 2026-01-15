<?php

namespace App\Http\Controllers;

use App\Actions\Dashboard\FetchDashboardDataAction;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, FetchDashboardDataAction $fetchDashboardData)
    {
        $data = $fetchDashboardData->execute($request->user());

        return Inertia::render('Dashboard', $data);
    }
}
