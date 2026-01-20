<?php

namespace App\Filament\Resources\Achievements\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AchievementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('slug')
                    ->required(),
                TextInput::make('name')
                    ->required(),
                Textarea::make('description')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('icon')
                    ->required(),
                TextInput::make('type')
                    ->required(),
                TextInput::make('threshold')
                    ->required()
                    ->numeric(),
                TextInput::make('category')
                    ->required()
                    ->default('general'),
            ]);
    }
}
