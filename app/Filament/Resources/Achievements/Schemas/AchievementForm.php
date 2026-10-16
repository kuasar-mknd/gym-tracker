<?php

declare(strict_types=1);

namespace App\Filament\Resources\Achievements\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AchievementForm
{
    /**
     * Chaque texte tient dans sa colonne (#2071) : 255 caractères, et mille
     * pour la description, comme les notes d'une séance. Sans borne, un
     * texte trop long finissait en 500 à l'enregistrement.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('slug')->label('Identifiant')
                    ->required()
                    ->maxLength(255),
                TextInput::make('name')->label('Nom')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')->label('Description')
                    ->required()
                    ->maxLength(1000)
                    ->columnSpanFull(),
                TextInput::make('icon')->label('Icône')
                    ->required()
                    ->maxLength(255),
                TextInput::make('type')->label('Type')
                    ->required()
                    ->maxLength(255),
                TextInput::make('threshold')->label('Seuil')
                    ->required()
                    ->numeric(),
                TextInput::make('category')->label('Catégorie')
                    ->required()
                    ->maxLength(255)
                    ->default('general'),
            ]);
    }
}
