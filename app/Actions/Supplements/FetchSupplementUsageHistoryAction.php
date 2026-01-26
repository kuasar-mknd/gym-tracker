<?php

namespace App\Actions\Supplements;

use App\Models\SupplementLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class FetchSupplementUsageHistoryAction
{
    /**
     * @return array<int, array{date: string, count: float}>
     */
    public function execute(User $user): array
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

        /** @var \Illuminate\Support\Collection<string, float> $results */
        $results = $usageHistoryRaw;

        return $this->fillUsageHistory($results, $days);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, float>  $usageHistoryRaw
     * @return array<int, array{date: string, count: float}>
     */
    private function fillUsageHistory(\Illuminate\Support\Collection $usageHistoryRaw, int $days): array
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
