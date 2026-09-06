<?php

declare(strict_types=1);

namespace App\Filament\Resources\ErreursNavigateur\Pages;

use App\Filament\Resources\ErreursNavigateur\ErreurNavigateurResource;
use Filament\Resources\Pages\ListRecords;

class ListErreursNavigateur extends ListRecords
{
    #[\Override]
    protected static string $resource = ErreurNavigateurResource::class;
}
