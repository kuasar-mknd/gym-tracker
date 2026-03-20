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

class WaterController extends Controller
{
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

    public function destroy(WaterLog $waterLog): RedirectResponse
    {
        $this->authorize('delete', $waterLog);

        $waterLog->delete();

        return redirect()->back();
    }
}
