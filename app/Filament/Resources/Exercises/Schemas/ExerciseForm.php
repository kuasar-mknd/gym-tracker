<?php

declare(strict_types=1);

namespace App\Filament\Resources\Exercises\Schemas;

use App\Enums\ExerciseCategory;
use App\Models\Exercise;
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

    /**
     * Chaque champ tient dans sa colonne (#2071) : le nom dans ses 255
     * caractères, le repos sous `Exercise::REPOS_MAX_SECONDES`, et la
     * catégorie, que le modèle lit comme une `ExerciseCategory`, parmi les
     * catégories de l'application. En saisie libre, une catégorie inconnue
     * ou un texte trop long finissaient en 500 à l'enregistrement.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('Nom')
                    ->required()
                    ->maxLength(255),
                Select::make('type')->label('Type')
                    ->options(self::TYPES)
                    ->default('strength')
                    ->required(),
                TextInput::make('default_rest_time')->label('Repos par défaut (s)')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(Exercise::REPOS_MAX_SECONDES),
                Select::make('category')->label('Catégorie')
                    ->options(array_column(ExerciseCategory::cases(), 'value', 'value')),
                Select::make('user_id')->label('Compte')
                    ->relationship('user', 'name')
                    ->searchable(),
            ]);
    }
}
