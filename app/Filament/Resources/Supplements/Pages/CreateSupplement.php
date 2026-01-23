<?php

declare(strict_types=1);

namespace App\Filament\Resources\Supplements\Pages;

use App\Filament\Resources\Supplements\SupplementResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplement extends CreateRecord
{
    protected static string $resource = SupplementResource::class;
}
