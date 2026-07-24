<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(self::getComponents());
    }

    /** @return array<int, \Illuminate\Contracts\Support\Htmlable|string> */
    private static function getComponents(): array
    {
        return [
            TextInput::make('name')->required(),
            TextInput::make('email')->label('Email address')->email()->required(),
            TextInput::make('default_rest_time')->required()->numeric()->default(90),
            DateTimePicker::make('email_verified_at'),
            TextInput::make('provider'),
            TextInput::make('provider_id'),
            TextInput::make('avatar'),
            TextInput::make('password')->password(),
            TextInput::make('current_streak')->required()->numeric()->default(0)->readOnly(),
            TextInput::make('longest_streak')->required()->numeric()->default(0)->readOnly(),
            DateTimePicker::make('last_workout_at')->readOnly(),
        ];
    }
}
