<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class UserActivityChart extends ChartWidget
{
    protected ?string $heading = 'User Registrations';

    protected function getData(): array
    {
        $usersPerMonth = User::selectRaw('COUNT(*) as count, MONTH(created_at) as month')
            ->whereYear('created_at', Carbon::now()->year)
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('count', 'month')
            ->toArray();

        // Fill missing months with 0
        $data = [];
        $labels = [];
        for ($i = 1; $i <= 12; $i++) {
            $data[] = $usersPerMonth[$i] ?? 0;
            $labels[] = Carbon::create()->month($i)->format('M');
        }

        return [
            'datasets' => [
                [
                    'label' => 'New Users',
                    'data' => $data,
                    'backgroundColor' => '#8b5cf6', // Violet
                    'borderColor' => '#8b5cf6',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
