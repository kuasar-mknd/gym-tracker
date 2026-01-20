<?php

namespace App\Filament\Resources\Exercises\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ExerciseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                Select::make('type')
                    ->options(['strength' => 'Strength', 'cardio' => 'Cardio', 'timed' => 'Timed'])
                    ->default('strength')
                    ->required(),
                TextInput::make('default_rest_time')
                    ->numeric(),
                TextInput::make('category'),
                Select::make('user_id')
                    ->relationship('user', 'name'),
            ]);
    }
}
