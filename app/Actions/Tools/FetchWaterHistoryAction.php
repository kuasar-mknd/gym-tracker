<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Models\User;
use App\Models\WaterLog;
use Carbon\Carbon;

class FetchWaterHistoryAction
{
    /**
     * L'hydratation des sept derniers jours.
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

        // Groupé une fois par date : filtrer la collection jour après jour
        // coûtait O(n×7), là où un groupement coûte O(n) puis sept lectures
        // directes.
        $groupedLogs = $historyLogs->groupBy(
            static fn (WaterLog $log): string => $log->consumed_at->format('Y-m-d')
        );

        $history = [];
        for ($i = 6; $i >= 0; $i--) {
            // `copy()` plutôt qu'une nouvelle instance de Carbon à chaque tour.
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
