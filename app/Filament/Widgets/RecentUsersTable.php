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

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->query(User::query()->latest()->limit(10))
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
