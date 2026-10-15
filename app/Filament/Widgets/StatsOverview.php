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

    /**
     * Les tuiles du tableau de bord. « Comptes » et « Nouveaux comptes (7 j) »
     * ne comptent que les comptes ouverts ; ceux qui attendent encore le lien
     * envoyé à leur adresse se comptent à part, sous « Comptes ».
     */
    #[\Override]
    protected function getStats(): array
    {
        return [
            Stat::make('Comptes', User::ouverts()->count())
                ->description(sprintf('Comptes ouverts, et %d en attente du lien', User::enAttente()->count()))
                ->descriptionIcon('heroicon-m-user-group')
                ->chart([7, 2, 10, 3, 15, 4, 17])
                ->color('success'),
            Stat::make('Nouveaux comptes (7 j)', $this->getNewUsersCount())
                ->description('Ouverts, inscrits ces sept derniers jours')
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

    /**
     * Les comptes ouverts inscrits ces sept derniers jours. Un compte encore en
     * attente du lien n'en est pas un (`User::ouverts()`) : il ne se connecte
     * pas, et s'efface s'il n'est jamais ouvert.
     */
    private function getNewUsersCount(): int
    {
        return User::ouverts()->where('created_at', '>=', Carbon::now()->subDays(7))->count();
    }

    /**
     * Les séances commencées aujourd'hui.
     *
     * Les deux bornes du jour se tirent d'une seule lecture de l'horloge : avec
     * une lecture chacune, quand minuit passait entre elles, le jour couvrait
     * aussi la veille (#2033).
     */
    private function getWorkoutsTodayCount(): int
    {
        $debutDuJour = Carbon::today();

        return Workout::whereBetween('started_at', [$debutDuJour, $debutDuJour->copy()->endOfDay()])->count();
    }
}
