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
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('brand'),
                TextInput::make('dosage'),
                TextInput::make('servings_remaining')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('low_stock_threshold')
                    ->required()
                    ->numeric()
                    ->default(5),
            ]);
    }
}
