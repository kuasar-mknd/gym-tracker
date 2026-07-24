<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Models\User;
use App\Models\WaterLog;
use Carbon\Carbon;

class FetchWaterHistoryAction
{
    /**
     * Get the water consumption history for the last 7 days.
     *
     * @return array<int, array{date: string, day_name: string, total: float}>
     */
    public function execute(User $user): array
    {
        $now = Carbon::now();
        $startDate = $now->copy()->subDays(6)->startOfDay();
        $historyLogs = $user->waterLogs()
            ->where('consumed_at', '>=', $startDate)
            ->get();

        // ⚡ Bolt: Group by date string to change O(n*7) collection filtering into O(n) + O(1) lookups
        $groupedLogs = $historyLogs->groupBy(function (WaterLog $log): string {
            /** @var \Carbon\Carbon $consumedAt */
            $consumedAt = $log->consumed_at;

            return $consumedAt->format('Y-m-d');
        });

        $history = [];
        for ($i = 6; $i >= 0; $i--) {
            // ⚡ Bolt: Use copy() instead of new Carbon instance inside loop
            $date = $now->copy()->subDays($i);
            $dateString = $date->format('Y-m-d');

            /** @var float|int $dayTotal */
            $dayTotal = $groupedLogs->get($dateString)?->sum('amount') ?? 0;

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
