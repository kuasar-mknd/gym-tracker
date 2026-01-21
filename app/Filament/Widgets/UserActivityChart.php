<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class UserActivityChart extends ChartWidget
{
    protected ?string $heading = 'User Registrations';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $usersPerMonth = $this->getUsersPerMonth();

        return [
            'datasets' => [
                [
                    'label' => 'New Users',
                    'data' => $this->fillMonthlyData($usersPerMonth)['data'],
                    'backgroundColor' => '#8b5cf6', // Violet
                    'borderColor' => '#8b5cf6',
                ],
            ],
            'labels' => $this->fillMonthlyData($usersPerMonth)['labels'],
        ];
    }

    /** @return array<int, int> */
    private function getUsersPerMonth(): array
    {
        /** @var array<int, int> $data */
        $data = User::selectRaw('COUNT(*) as count, MONTH(created_at) as month')
            ->whereYear('created_at', Carbon::now()->year)
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('count', 'month')
            ->toArray();

        return $data;
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
            $labels[] = ($date = Carbon::create(null, $i, 1)) ? $date->format('M') : '';
        }

        return ['data' => $data, 'labels' => $labels];
    }
}
