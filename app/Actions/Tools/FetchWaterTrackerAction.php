<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Models\User;
use App\Models\WaterLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class FetchWaterTrackerAction
{
    /**
     * Les prises d'eau du jour et leur total.
     *
     * Les deux bornes du jour se tirent d'une seule lecture de l'horloge : avec
     * une lecture par borne, quand minuit passait entre elles, le jour couvrait
     * aussi la veille, et le total du jour comptait l'eau de la veille (#2017).
     * La page Hydratation passe le même instant au graphique de la semaine
     * (#2033).
     *
     * Avec `$prises`, les prises de la semaine que `FetchWaterHistoryAction::prisesDeLaSemaine()`
     * a lues pour le même instant, le jour s'en tire sans relire la base (#2351).
     *
     * @param  Carbon|null  $maintenant  L'instant de l'opération ; à défaut, l'horloge.
     * @param  Collection<int, WaterLog>|null  $prises  Les prises de la semaine, la plus récente en tête.
     * @return array{logs: Collection<int, WaterLog>, todayTotal: float|int}
     */
    public function execute(User $user, ?Carbon $maintenant = null, ?Collection $prises = null): array
    {
        $debutDuJour = ($maintenant ?? Carbon::now())->copy()->startOfDay();
        $finDuJour = $debutDuJour->copy()->endOfDay();

        $todayLogs = $prises?->filter(
            static fn (WaterLog $prise): bool => $prise->consumed_at->betweenIncluded($debutDuJour, $finDuJour)
        )->values() ?? $user->waterLogs()
            // Un intervalle plutot que `whereDate()`, qui rendait inutilisable
            // `water_logs(user_id, consumed_at)`. Meme forme que
            // `WaterLog::scopeConsumedAtBetween`.
            ->whereBetween('consumed_at', [$debutDuJour, $finDuJour])
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
