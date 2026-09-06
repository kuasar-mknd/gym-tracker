<?php

declare(strict_types=1);

namespace App\Filament\Resources\TachesPlanifiees\Pages;

use App\Filament\Resources\TachesPlanifiees\TachePlanifieeResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Artisan;

class ListTachesPlanifiees extends ListRecords
{
    #[\Override]
    protected static string $resource = TachePlanifieeResource::class;

    /**
     * Le planning se relit au déploiement (`schedule-monitor:sync` dans
     * l'image) ; ce bouton sert quand une tâche a changé sans déploiement.
     *
     * @return array<int, Action>
     */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('synchroniser')
                ->label('Relire le planning')
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    Artisan::call('schedule-monitor:sync');

                    Notification::make()->title('Planning relu')->success()->send();
                }),
        ];
    }
}
