<?php

declare(strict_types=1);

namespace App\Filament\Resources\Supplements\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SupplementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')->label('Compte')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->required(),
                TextInput::make('name')->label('Nom')
                    ->required(),
                TextInput::make('brand')->label('Marque'),
                TextInput::make('dosage')->label('Dosage'),
                TextInput::make('servings_remaining')->label('Doses restantes')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('low_stock_threshold')->label('Seuil de stock bas')
                    ->required()
                    ->numeric()
                    ->default(5),
            ]);
    }
}
