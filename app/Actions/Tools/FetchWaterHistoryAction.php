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

        /*
         * Les sept jours à rebours depuis maintenant, du plus ancien à
         * aujourd'hui, sur un `range()` et non un compteur : `$i++` à la place
         * de `$i--` ne finissait jamais, et ce mutant tenait un processus de la
         * passe nocturne jusqu'au délai que Pest accorde à chaque mutant. Pas
         * une période partie de minuit non plus : là où minuit manque le jour
         * du passage à l'heure d'été, elle perdait aujourd'hui (#2017).
         */
        $historique = [];
        foreach (range(6, 0) as $joursEcoules) {
            $date = $now->copy()->subDays($joursEcoules);
            $dateString = $date->format('Y-m-d');

            /** @var float|int $dayTotal */
            $dayTotal = $groupedLogs->get($dateString)?->sum('amount') ?? 0;

            $dayTotalValue = (float) $dayTotal;

            $historique[] = [
                'date' => $dateString,
                'day_name' => $date->dayName,
                'total' => $dayTotalValue,
            ];
        }

        return $historique;
    }
}
