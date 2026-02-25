<?php

declare(strict_types=1);

namespace App\Filament\Resources\Exercises\Pages;

use App\Filament\Resources\Exercises\ExerciseResource;
use Database\Seeders\PrecorExerciseSeeder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListExercises extends ListRecords
{
    protected static string $resource = ExerciseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('installerParDefaut')
                ->label('Installer Exercices par Défaut')
                ->icon('heroicon-o-plus-circle')
                ->color('info')
                ->requiresConfirmation()
                ->action(function (PrecorExerciseSeeder $seeder): void {
                    $seeder->run();

                    Notification::make()
                        ->title('Exercices installés avec succès !')
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
