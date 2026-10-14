<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentUsersTable extends TableWidget
{
    #[\Override]
    protected static ?int $sort = 3;

    #[\Override]
    protected int|string|array $columnSpan = 'full';

    /**
     * Les dix derniers inscrits dont le compte est ouvert. Un compte encore en
     * attente du lien n'y figure pas (`User::ouverts()`) : il ne se connecte
     * pas, s'efface s'il n'est jamais ouvert, et son adresse peut être celle
     * d'un tiers. La liste des utilisateurs le montre, avec son badge et son
     * filtre « En attente du lien ».
     *
     * Le titre se pose ici : sans lui, Filament tire du nom de la classe
     * « Recent Users Table », en anglais.
     */
    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->heading('Derniers inscrits')
            ->query(User::ouverts()->latest()->limit(10))
            ->columns([
                // Pas de `searchable()` : chaque frappe declenchait deux
                // parcours complets de `users` — la page et son COUNT — pour un
                // `LIKE '%…%'` qu'aucun index B-tree ne sert. Un encart de dix
                // inscrits recents n'a pas de recherche a offrir.
                TextColumn::make('name')->label('Nom'),
                TextColumn::make('email')->label('Adresse e-mail'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->label('Inscription'),
                TextColumn::make('last_workout_at')
                    ->dateTime()
                    ->label('Dernière activité'),
            ]);
    }
}
