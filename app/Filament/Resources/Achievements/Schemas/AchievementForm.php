<?php

declare(strict_types=1);

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
                TextInput::make('slug')->label('Identifiant')
                    ->required(),
                TextInput::make('name')->label('Nom')
                    ->required(),
                Textarea::make('description')->label('Description')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('icon')->label('Icône')
                    ->required(),
                TextInput::make('type')->label('Type')
                    ->required(),
                TextInput::make('threshold')->label('Seuil')
                    ->required()
                    ->numeric(),
                TextInput::make('category')->label('Catégorie')
                    ->required()
                    ->default('general'),
            ]);
    }
}
