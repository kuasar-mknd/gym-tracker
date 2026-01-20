<?php

namespace App\Filament\Resources\Goals\Pages;

use App\Filament\Resources\Goals\GoalResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGoals extends ListRecords
{
    protected static string $resource = GoalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
