<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class StatsOverview extends StatsOverviewWidget
{
    #[\Override]
    protected ?string $pollingInterval = '30s';

    #[\Override]
    protected function getStats(): array
    {
        return [
            Stat::make('Comptes', User::count())
                ->description('Comptes inscrits')
                ->descriptionIcon('heroicon-m-user-group')
                ->chart([7, 2, 10, 3, 15, 4, 17])
                ->color('success'),
            Stat::make('Nouveaux comptes (7 j)', $this->getNewUsersCount())
                ->description('Inscrits ces sept derniers jours')
                ->descriptionIcon('heroicon-m-user-plus')
                ->color('info'),
            Stat::make('Séances du jour', $this->getWorkoutsTodayCount())
                ->description("Séances commencées aujourd'hui")
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
            Stat::make('Exercices communs', Exercise::whereNull('user_id')->count())
                ->description('Taille de la bibliothèque commune')
                ->descriptionIcon('heroicon-m-book-open')
                ->color('primary'),
        ];
    }

    private function getNewUsersCount(): int
    {
        return User::where('created_at', '>=', Carbon::now()->subDays(7))->count();
    }

    private function getWorkoutsTodayCount(): int
    {
        return Workout::whereBetween('started_at', [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()])->count();
    }
}
