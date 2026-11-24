<?php

declare(strict_types=1);

namespace App\Actions\Stats;

/**
 * Ce que la vue d'ensemble des statistiques affiche d'emblée : la période
 * demandée ; les calculs lourds restent dans les services.
 */
class FetchStatsOverviewAction
{
    /**
     * La période affichée, que la page recopie dans son sélecteur.
     *
     * @param  string  $period  La période demandée, telle qu'elle arrive de la requête (« 30j »).
     * @return array{selectedPeriod: string}
     */
    public function chiffresImmediats(string $period): array
    {
        return ['selectedPeriod' => $period];
    }

    /**
     * La période en nombre de jours ; trente pour tout ce qui n'est pas reconnu.
     */
    public function parsePeriod(string $period): int
    {
        return match ($period) {
            '7j' => 7,
            '30j' => 30,
            '90j' => 90,
            '1a' => 365,
            default => 30,
        };
    }
}
