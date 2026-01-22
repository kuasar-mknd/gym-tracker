<?php

namespace App\Http\Controllers;

use App\Actions\Tools\FetchWaterTrackerDataAction;
use App\Http\Requests\StoreWaterLogRequest;
use App\Models\WaterLog;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class WaterController extends Controller
{
    public function index(FetchWaterTrackerDataAction $fetchWaterTrackerData): Response
    {
        return Inertia::render('Tools/WaterTracker', $fetchWaterTrackerData->execute($this->user()));
    }

    public function store(StoreWaterLogRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (! isset($data['consumed_at'])) {
            $data['consumed_at'] = now();
        }

        $this->user()->waterLogs()->create($data);

        return redirect()->back();
    }

    public function destroy(WaterLog $waterLog): RedirectResponse
    {
        if ($waterLog->user_id !== $this->user()->id) {
            abort(403);
        }

        $waterLog->delete();

        return redirect()->back();
    }
}
