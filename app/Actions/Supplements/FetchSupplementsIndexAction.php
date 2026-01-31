<?php

declare(strict_types=1);

namespace App\Actions\Supplements;

use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FetchSupplementsIndexAction
{
    /**
     * @return array{
     *     supplements: \Illuminate\Support\Collection<int, array{id: int, name: string, icon: string, current_log: float, unit: string, daily_goal: null}>,
     *     usageHistory: array<int, array{date: string, count: float}>
     * }
     */
    public function execute(User $user): array
    {
        return [
            'supplements' => $this->getSupplementsWithLatestLog($user),
            'usageHistory' => $this->getUsageHistory($user),
        ];
    }

    /**
     * Retrieve supplements with their latest log status.
     *
     * @return \Illuminate\Support\Collection<int, array{id: int, name: string, icon: string, current_log: float, unit: string, daily_goal: null}>
     */
    private function getSupplementsWithLatestLog(User $user): Collection
    {
        return Supplement::forUser($user->id)
            ->with(['latestLog'])
            ->get()
            ->map(fn (Supplement $supplement): array => [
                'id' => (int) $supplement->id,
                'name' => (string) $supplement->name,
                'icon' => 'heroicon-o-beaker',
                'current_log' => (float) ($supplement->latestLog->quantity ?? 0.0),
                'unit' => 'servings',
                'daily_goal' => null,
            ]);
    }

    /**
     * Get the supplement usage history for the last 30 days.
     *
     * @return array<int, array{date: string, count: float}>
     */
    private function getUsageHistory(User $user): array
    {
        $days = 30;
        $usageHistoryRaw = SupplementLog::where('user_id', $user->id)
            ->where('consumed_at', '>=', now()->subDays($days)->startOfDay())
            ->select(
                DB::raw('DATE(consumed_at) as date'),
                DB::raw('SUM(quantity) as count')
            )
            ->groupBy('date')
            ->get()
            ->pluck('count', 'date');

        /** @var Collection<string, float> $results */
        $results = $usageHistoryRaw;

        return $this->fillUsageHistory($results, $days);
    }

    /**
     * Fill missing dates in the usage history with zero values.
     *
     * @param  Collection<string, float>  $usageHistoryRaw
     * @return array<int, array{date: string, count: float}>
     */
    private function fillUsageHistory(Collection $usageHistoryRaw, int $days): array
    {
        $history = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $carbonDate = now()->subDays($i);
            $dateKey = $carbonDate->format('Y-m-d');
            $dateString = $carbonDate->format('d/m');

            $rawTotal = $usageHistoryRaw[$dateKey] ?? 0.0;

            $history[] = [
                'date' => $dateString,
                'count' => (float) $rawTotal,
            ];
        }

        return $history;
    }
}
