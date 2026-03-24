<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Models\User;
use Carbon\Carbon;

class FetchWaterTrackerAction
{
    /**
     * Get the today's water logs and total amount for the user.
     *
     * @return array{logs: \Illuminate\Database\Eloquent\Collection<int, \App\Models\WaterLog>, todayTotal: float|int}
     */
    public function execute(User $user): array
    {
        $todayLogs = $user->waterLogs()
            ->whereDate('consumed_at', Carbon::today())
            ->orderByDesc('consumed_at')
            ->get();

        /** @var float|int $todayTotal */
        $todayTotal = $todayLogs->sum('amount');

        return [
            'logs' => $todayLogs,
            'todayTotal' => $todayTotal,
        ];
    }
}
