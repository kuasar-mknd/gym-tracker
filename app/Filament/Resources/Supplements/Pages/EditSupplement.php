<?php

declare(strict_types=1);

namespace App\Filament\Resources\Supplements\Pages;

use App\Filament\Resources\Supplements\SupplementResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSupplement extends EditRecord
{
    protected static string $resource = SupplementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
