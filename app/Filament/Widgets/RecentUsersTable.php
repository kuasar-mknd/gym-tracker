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
                TextColumn::make('name'),
                TextColumn::make('email'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->label('Registration Date'),
                TextColumn::make('last_workout_at')
                    ->dateTime()
                    ->label('Last Activity'),
            ]);
    }
}
