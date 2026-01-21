<?php

namespace App\Filament\Resources\Goals\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class GoalForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(self::getComponents());
    }

    /** @return array<\Filament\Forms\Components\Component> */
    private static function getComponents(): array
    {
        return [
            Select::make('user_id')->relationship('user', 'name')->required(),
            TextInput::make('title')->required(),
            Select::make('type')->options([
                'weight' => 'Weight',
                'frequency' => 'Frequency',
                'volume' => 'Volume',
                'measurement' => 'Measurement',
            ])->required(),
            TextInput::make('target_value')->required()->numeric(),
            TextInput::make('current_value')->required()->numeric()->default(0),
            TextInput::make('start_value')->required()->numeric()->default(0),
            Select::make('exercise_id')->relationship('exercise', 'name'),
            TextInput::make('measurement_type'),
            DatePicker::make('deadline'),
            DateTimePicker::make('completed_at'),
        ];
    }
}
