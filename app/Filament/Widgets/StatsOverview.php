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
    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        return [
            Stat::make('Total Users', User::count())
                ->description('Total registered users')
                ->descriptionIcon('heroicon-m-user-group')
                ->chart([7, 2, 10, 3, 15, 4, 17])
                ->color('success'),
            Stat::make('New Users (7d)', $this->getNewUsersCount())
                ->description('Users joined in last 7 days')
                ->descriptionIcon('heroicon-m-user-plus')
                ->color('info'),
            Stat::make('Workouts Today', $this->getWorkoutsTodayCount())
                ->description('Sessions started today')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
            Stat::make('System Exercises', Exercise::whereNull('user_id')->count())
                ->description('Global exercise library size')
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
        return Workout::whereDate('started_at', Carbon::today())->count();
    }
}
