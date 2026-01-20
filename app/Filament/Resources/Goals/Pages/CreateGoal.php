<?php

namespace App\Filament\Resources\Goals\Pages;

use App\Filament\Resources\Goals\GoalResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGoal extends CreateRecord
{
    protected static string $resource = GoalResource::class;
}
