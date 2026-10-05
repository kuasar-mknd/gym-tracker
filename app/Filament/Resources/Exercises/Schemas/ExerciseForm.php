<?php

declare(strict_types=1);

namespace App\Filament\Resources\Exercises\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ExerciseForm
{
    /**
     * Les types d'exercice, nommés comme l'application les nomme
     * (`resources/js/Utils/constants.js`). Le panneau les montrait en anglais
     * (« Strength », « Timed ») (#1979).
     *
     * @var array<string, string>
     */
    public const array TYPES = [
        'strength' => 'Force',
        'cardio' => 'Cardio',
        'timed' => 'Temps',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('Nom')
                    ->required(),
                Select::make('type')->label('Type')
                    ->options(self::TYPES)
                    ->default('strength')
                    ->required(),
                TextInput::make('default_rest_time')->label('Repos par défaut (s)')
                    ->numeric(),
                TextInput::make('category')->label('Catégorie'),
                Select::make('user_id')->label('Compte')
                    ->relationship('user', 'name')
                    ->searchable(),
            ]);
    }
}
