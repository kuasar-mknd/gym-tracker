<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Models\User;
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
        $startDate = Carbon::now()->subDays(6)->startOfDay();

        // ⚡ Bolt Optimization: Calculate daily totals in the database using GROUP BY
        // instead of loading all logs into memory and calculating in PHP.
        // Impact: Reduces memory usage and CPU time for users with many water entries.
        $historyLogs = $user->waterLogs()
            ->where('consumed_at', '>=', $startDate)
            ->selectRaw('DATE(consumed_at) as date, SUM(amount) as total')
            ->groupBy('date')
            ->pluck('total', 'date');

        $history = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $dateString = $date->format('Y-m-d');

            $dayTotalValue = (float) ($historyLogs[$dateString] ?? 0);

            $history[] = [
                'date' => $dateString,
                'day_name' => $date->dayName,
                'total' => $dayTotalValue,
            ];
        }

        return $history;
    }
}
