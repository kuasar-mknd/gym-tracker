<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Models\User;
use Carbon\Carbon;

class FetchWaterTrackerAction
{
    /**
     * Les prises d'eau du jour et leur total.
     *
     * Les deux bornes du jour se tirent d'une seule lecture de l'horloge : avec
     * une lecture par borne, quand minuit passait entre elles, le jour couvrait
     * aussi la veille, et le total du jour comptait l'eau de la veille (#2017).
     *
     * @return array{logs: \Illuminate\Database\Eloquent\Collection<int, \App\Models\WaterLog>, todayTotal: float|int}
     */
    public function execute(User $user): array
    {
        $debutDuJour = Carbon::today();

        $todayLogs = $user->waterLogs()
            // Un intervalle plutot que `whereDate()`, qui rendait inutilisable
            // `water_logs(user_id, consumed_at)`. Meme forme que
            // `WaterLog::scopeConsumedAtBetween`.
            ->whereBetween('consumed_at', [$debutDuJour, $debutDuJour->copy()->endOfDay()])
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
