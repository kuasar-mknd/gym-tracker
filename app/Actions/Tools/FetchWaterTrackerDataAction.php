<?php

namespace App\Actions\Tools;

use App\Models\User;
use App\Models\WaterLog;
use Carbon\Carbon;

class FetchWaterTrackerDataAction
{
    /**
     * @return array{logs: \Illuminate\Database\Eloquent\Collection, todayTotal: float, history: array, goal: int}
     */
    public function execute(User $user): array
    {
        $todayLogs = $user->waterLogs()
            ->whereDate('consumed_at', Carbon::today())
            ->orderByDesc('consumed_at')
            ->get();

        return [
            'logs' => $todayLogs,
            'todayTotal' => (float) $todayLogs->sum('amount'),
            'history' => $this->getWaterHistory($user),
            'goal' => 2500, // Hardcoded goal for now
        ];
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
            /** @var float|int $dayTotal */
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
