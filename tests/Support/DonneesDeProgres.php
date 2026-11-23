<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Stats\GetStatsDashboardAction;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Le groupe différé de la page Progrès, tel que la page le reçoit, ramené aux
 * trois séries mensuelles que lisent les tests de fenêtre : les deux fréquences
 * et le volume des six derniers mois.
 */
final class DonneesDeProgres
{
    /**
     * @return array{monthly_frequency: \Illuminate\Support\Collection<int, array{month: string, count: int}>, day_of_week_frequency: \Illuminate\Support\Collection<int, array{day: string, count: int}>, monthly_volume: array<int, \App\DTOs\Stats\MonthlyVolumePoint>}
     */
    public static function graphiques(User $user): array
    {
        $differees = app(GetStatsDashboardAction::class)->execute($user, Request::create('/stats'))['deferredData']();

        return [...$differees['regularite'], 'monthly_volume' => $differees['volumeMensuel']];
    }
}
