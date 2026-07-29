<?php

declare(strict_types=1);

namespace App\Filament\Resources\Goals\Pages;

use App\Filament\Resources\Goals\GoalResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGoal extends EditRecord
{
    #[\Override]
    protected static string $resource = GoalResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
