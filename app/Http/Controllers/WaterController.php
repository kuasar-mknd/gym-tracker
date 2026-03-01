<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tools\FetchWaterHistoryAction;
use App\Http\Requests\StoreWaterLogRequest;
use App\Models\User;
use App\Models\WaterLog;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class WaterController extends Controller
{
    public function index(FetchWaterHistoryAction $fetchWaterHistory): Response
    {
        $this->authorize('viewAny', WaterLog::class);

        /** @var User $user */
        $user = $this->user();

        $todayLogs = $user->waterLogs()
            ->whereDate('consumed_at', Carbon::today())
            ->orderByDesc('consumed_at')
            ->get();

        return Inertia::render('Tools/WaterTracker', [
            'logs' => $todayLogs,
            'todayTotal' => $todayLogs->sum('amount'),
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
