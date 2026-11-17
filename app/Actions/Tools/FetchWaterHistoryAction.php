<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Models\User;
use App\Models\WaterLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class FetchWaterHistoryAction
{
    /**
     * L'hydratation des sept derniers jours.
     *
     * La page Hydratation passe l'instant de son compteur du jour : la
     * dernière barre est alors le même jour que lui (#2033).
     *
     * @param  Carbon|null  $maintenant  L'instant de l'opération ; à défaut, l'horloge.
     * @param  Collection<int, WaterLog>|null  $prises  Les prises déjà lues par `prisesDeLaSemaine()` ; à défaut, une lecture.
     * @return array<int, array{date: string, day_name: string, total: float}>
     */
    public function execute(User $user, ?Carbon $maintenant = null, ?Collection $prises = null): array
    {
        $now = ($maintenant ?? Carbon::now())->copy();
        $historyLogs = $prises ?? $this->prisesDeLaSemaine($user, $now);

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

    /**
     * Les prises des sept jours qui finissent à l'instant donné, la plus
     * récente en tête : une seule lecture pour le compteur du jour
     * (`FetchWaterTrackerAction`) et pour ce graphique, qui en lisaient chacun
     * une (#2351).
     *
     * @return Collection<int, WaterLog>
     */
    public function prisesDeLaSemaine(User $user, Carbon $maintenant): Collection
    {
        return $user->waterLogs()
            ->where('consumed_at', '>=', $maintenant->copy()->subDays(6)->startOfDay())
            ->orderByDesc('consumed_at')
            ->orderByDesc('id')
            ->get();
    }
}
