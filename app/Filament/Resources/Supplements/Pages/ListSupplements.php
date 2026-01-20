<?php

namespace App\Filament\Resources\Supplements\Pages;

use App\Filament\Resources\Supplements\SupplementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSupplements extends ListRecords
{
    protected static string $resource = SupplementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
