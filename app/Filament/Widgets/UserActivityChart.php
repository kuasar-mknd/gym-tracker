<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\User;
use App\Support\Charte;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class UserActivityChart extends ChartWidget
{
    #[\Override]
    protected ?string $heading = 'User Registrations';

    protected function getType(): string
    {
        return 'bar';
    }

    #[\Override]
    protected function getData(): array
    {
        $usersPerMonth = $this->getUsersPerMonth();

        return [
            'datasets' => [
                [
                    'label' => 'New Users',
                    'data' => $this->fillMonthlyData($usersPerMonth)['data'],
                    'backgroundColor' => Charte::jeton('accent-tertiary'),
                    'borderColor' => Charte::jeton('accent-tertiary'),
                ],
            ],
            'labels' => $this->fillMonthlyData($usersPerMonth)['labels'],
        ];
    }

    /** @return array<int, int> */
    private function getUsersPerMonth(): array
    {
        /** @phpstan-ignore-next-line */
        return User::selectRaw('COUNT(*) as count, MONTH(created_at) as month')
            ->whereYear('created_at', Carbon::now()->year)
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('count', 'month')
            ->toArray();
    }

    /**
     * @param  array<int, int>  $usersPerMonth
     * @return array{data: array<int, int>, labels: array<int, string>}
     */
    private function fillMonthlyData(array $usersPerMonth): array
    {
        $data = [];
        $labels = [];
        for ($i = 1; $i <= 12; $i++) {
            $data[] = $usersPerMonth[$i] ?? 0;
            /*
             * `parse()` plutot que `create()`, et donc plus de ternaire.
             *
             * Le ternaire qui etait ici ne pouvait pas prendre sa branche
             * fausse — Carbon 3 rend une instance ou leve, la ou Carbon 2
             * rendait false — mais `create()` reste declare nullable, donc le
             * retirer seul ne faisait que deplacer le probleme. `parse()` ne
             * l'est pas : l'annee est arbitraire, seul le mois est lu.
             */
            $labels[] = Carbon::parse(sprintf('2000-%02d-01', $i))->format('M');
        }

        return ['data' => $data, 'labels' => $labels];
    }
}
