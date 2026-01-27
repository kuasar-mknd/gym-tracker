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
    /** @return array<string, mixed> */
    public function execute(User $user): array
    {
        return [
            'supplements' => $this->getSupplementsWithLatestLog($user),
            'usageHistory' => $this->getUsageHistory($user),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, mixed> */
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

    /** @return array<int, array{date: string, count: float}> */
    private function getUsageHistory(User $user): array
    {
        $days = 30;
        /** @var \Illuminate\Support\Collection<string, float> $usageHistoryRaw */
        $usageHistoryRaw = SupplementLog::where('user_id', $user->id)
            ->where('consumed_at', '>=', now()->subDays($days)->startOfDay())
            ->select(DB::raw('DATE(consumed_at) as date'), DB::raw('SUM(quantity) as count'))
            ->groupBy('date')
            ->get()
            ->pluck('count', 'date');

        return $this->fillUsageHistory($usageHistoryRaw, $days);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, float>  $usageHistoryRaw
     * @return array<int, array{date: string, count: float}>
     */
    private function fillUsageHistory(Collection $usageHistoryRaw, int $days): array
    {
        $history = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $carbonDate = now()->subDays($i);
            $history[] = [
                'date' => $carbonDate->format('d/m'),
                'count' => (float) ($usageHistoryRaw->get($carbonDate->format('Y-m-d')) ?? 0.0),
            ];
        }

        return $history;
    }
}
