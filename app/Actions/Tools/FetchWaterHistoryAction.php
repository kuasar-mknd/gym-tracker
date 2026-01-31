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
     * @param  \App\Models\User  $user
     * @return array<int, array{date: string, day_name: string, total: float}>
     */
    public function execute(User $user): array
    {
        $startDate = Carbon::now()->subDays(6)->startOfDay();
        $historyLogs = $user->waterLogs()
            ->where('consumed_at', '>=', $startDate)
            ->get();

        $history = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $dateString = $date->format('Y-m-d');

            /** @var float|int $dayTotal */
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
