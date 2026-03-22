<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tools\FetchWaterHistoryAction;
use App\Actions\Tools\FetchWaterTrackerAction;
use App\Http\Requests\StoreWaterLogRequest;
use App\Models\User;
use App\Models\WaterLog;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing user water tracking.
 *
 * This controller handles logging daily water intake and viewing historical data.
 */
class WaterController extends Controller
{
    /**
     * Display the user's water tracker and history.
     *
     * @param  \App\Actions\Tools\FetchWaterHistoryAction  $fetchWaterHistory  Action to fetch historical water data.
     * @param  \App\Actions\Tools\FetchWaterTrackerAction  $fetchWaterTracker  Action to fetch today's water data.
     * @return \Inertia\Response The Inertia response rendering the Tools/WaterTracker page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function index(FetchWaterHistoryAction $fetchWaterHistory, FetchWaterTrackerAction $fetchWaterTracker): Response
    {
        $this->authorize('viewAny', WaterLog::class);

        /** @var User $user */
        $user = $this->user();

        $trackerData = $fetchWaterTracker->execute($user);

        return Inertia::render('Tools/WaterTracker', [
            ...$trackerData,
            'history' => $fetchWaterHistory->execute($user),
            'goal' => 2500, // Hardcoded goal for now
        ]);
    }

    /**
     * Store a new water log entry.
     *
     * @param  \App\Http\Requests\StoreWaterLogRequest  $request  The validated request containing the water log amount.
     * @return \Illuminate\Http\RedirectResponse Redirects back to the water tracker page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function store(StoreWaterLogRequest $request): RedirectResponse
    {
        $this->authorize('create', WaterLog::class);

        $data = $request->validated();

        if (! isset($data['consumed_at'])) {
            $data['consumed_at'] = now();
        }

        $this->user()->waterLogs()->create($data);

        return redirect()->back();
    }

    /**
     * Delete an existing water log entry.
     *
     * @param  \App\Models\WaterLog  $waterLog  The water log entry to delete.
     * @return \Illuminate\Http\RedirectResponse Redirects back to the water tracker page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function destroy(WaterLog $waterLog): RedirectResponse
    {
        $this->authorize('delete', $waterLog);

        $waterLog->delete();

        return redirect()->back();
    }
}
