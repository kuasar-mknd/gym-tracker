<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWaterLogRequest;
use App\Models\WaterLog;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class WaterController extends Controller
{
    public function index(): Response
    {
        $user = $this->user();

        // Get today's logs
        $todayLogs = $user->waterLogs()
            ->whereDate('consumed_at', Carbon::today())
            ->orderByDesc('consumed_at')
            ->get();

        $todayTotal = $todayLogs->sum('amount');

        // Get history for the last 7 days
        $startDate = Carbon::now()->subDays(6)->startOfDay();
        $historyLogs = $user->waterLogs()
            ->where('consumed_at', '>=', $startDate)
            ->get();

        $history = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $dateString = $date->format('Y-m-d');

            $dayTotal = $historyLogs->filter(function ($log) use ($dateString) {
                return $log->consumed_at->format('Y-m-d') === $dateString;
            })->sum('amount');

            $history[] = [
                'date' => $dateString,
                'day_name' => $date->locale('en')->dayName,
                'total' => $dayTotal,
            ];
        }

        return Inertia::render('Tools/WaterTracker', [
            'logs' => $todayLogs,
            'todayTotal' => $todayTotal,
            'history' => $history,
            'goal' => 2500, // Hardcoded goal for now
        ]);
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
