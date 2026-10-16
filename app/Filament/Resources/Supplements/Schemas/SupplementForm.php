<?php

declare(strict_types=1);

namespace App\Filament\Resources\Supplements\Schemas;

use App\Models\Supplement;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SupplementForm
{
    /**
     * Chaque champ tient dans sa colonne (#2071) : les textes dans leurs 255
     * caractères, les doses sous `Supplement::DOSES_MAX`, comme dans
     * l'application. Sans borne, un texte trop long ou un nombre plus grand
     * qu'un entier finissaient en 500 à l'enregistrement.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')->label('Compte')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->required(),
                TextInput::make('name')->label('Nom')
                    ->required()
                    ->maxLength(255),
                TextInput::make('brand')->label('Marque')
                    ->maxLength(255),
                TextInput::make('dosage')->label('Dosage')
                    ->maxLength(255),
                TextInput::make('servings_remaining')->label('Doses restantes')
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(Supplement::DOSES_MAX)
                    ->default(0),
                TextInput::make('low_stock_threshold')->label('Seuil de stock bas')
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(Supplement::DOSES_MAX)
                    ->default(5),
            ]);
    }
}
