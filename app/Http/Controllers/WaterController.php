<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tools\FetchWaterHistoryAction;
use App\Actions\Tools\FetchWaterTrackerAction;
use App\Http\Requests\StoreWaterLogRequest;
use App\Models\User;
use App\Models\WaterLog;
use Illuminate\Auth\Access\AuthorizationException;
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
     * @param  FetchWaterHistoryAction  $fetchWaterHistory  Action to fetch historical water data.
     * @param  FetchWaterTrackerAction  $fetchWaterTracker  Action to fetch today's water data.
     * @return Response The Inertia response rendering the Tools/WaterTracker page.
     *
     * @throws AuthorizationException
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
     * @param  StoreWaterLogRequest  $request  The validated request containing the water log amount.
     * @return RedirectResponse Redirects back to the water tracker page.
     *
     * @throws AuthorizationException
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
     * @param  WaterLog  $waterLog  The water log entry to delete.
     * @return RedirectResponse Redirects back to the water tracker page.
     *
     * @throws AuthorizationException
     */
    public function destroy(WaterLog $waterLog): RedirectResponse
    {
        $this->authorize('delete', $waterLog);

        $waterLog->delete();

        return redirect()->back();
    }
}
