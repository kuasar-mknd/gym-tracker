<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWaterLogRequest;
use App\Models\User;
use App\Models\WaterLog;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class WaterController extends Controller
{
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->user();

        $todayLogs = $user->waterLogs()
            ->whereDate('consumed_at', Carbon::today())
            ->orderByDesc('consumed_at')
            ->get();

        return Inertia::render('Tools/WaterTracker', [
            'logs' => $todayLogs,
            'todayTotal' => $todayLogs->sum('amount'),
            'history' => $this->getWaterHistory($user),
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

    /** @return array<int, array{date: string, day_name: string, total: float}> */
    private function getWaterHistory(User $user): array
    {
        $startDate = Carbon::now()->subDays(6)->startOfDay();
        $historyLogs = $user->waterLogs()
            ->where('consumed_at', '>=', $startDate)
            ->get();

        $history = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $dateString = $date->format('Y-m-d');

            $dayTotal = $historyLogs->filter(function (WaterLog $log) use ($dateString): bool {
                /** @var \Carbon\Carbon $consumedAt */
                $consumedAt = $log->consumed_at;

                return $consumedAt->format('Y-m-d') === $dateString;
            })->sum('amount');
            $dayTotalValue = (float) $dayTotal;

            $history[] = [
                'date' => $dateString,
                'day_name' => $date->dayName,
                'total' => $dayTotalValue,
            ];
        }

        return $history;
    }
}
