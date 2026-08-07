<?php

declare(strict_types=1);

namespace App\Actions\Supplements;

use App\Http\Resources\SupplementResource;
use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;
use Illuminate\Support\Collection;

final class FetchSupplementsIndexAction
{
    /**
     * @return array{
     *     supplements: Collection<int, array<string, mixed>>,
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
     * Shaped by SupplementResource, which already describes exactly what the
     * page reads. The hand-rolled array this replaced sent id, name, icon,
     * current_log, unit and daily_goal — the last four read by nothing, anywhere
     * — while omitting brand, dosage, servings_remaining, low_stock_threshold
     * and last_taken_at, every one of which the card renders. So the stock
     * figure and the low-stock colouring showed undefined, and the edit form
     * pre-filled itself with nothing, which the update request then rejected.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function getSupplementsWithLatestLog(User $user): Collection
    {
        // toArray() rather than resolve(): resolve() is declared as a bare `array`,
        // so at level 9 its values are implicit mixed and will not satisfy the
        // explicit mixed above. The resource declares array<string, mixed>.
        return Supplement::forUser($user->id)
            ->with(['latestLog'])
            ->get()
            ->map(fn (Supplement $supplement): array => new SupplementResource($supplement)->toArray(request()))
            ->values();
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
            ->selectRaw('DATE(consumed_at) as date, SUM(quantity) as count')
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
