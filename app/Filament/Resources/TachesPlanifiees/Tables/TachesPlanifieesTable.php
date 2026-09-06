<?php

declare(strict_types=1);

namespace App\Filament\Resources\TachesPlanifiees\Tables;

use App\Models\TachePlanifiee;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TachesPlanifieesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Tâche')->sortable()->searchable(),
                TextColumn::make('cron_expression')
                    ->label('Fréquence')
                    ->state(fn (TachePlanifiee $record): string => $record->expressionLisible())
                    ->tooltip(fn (TachePlanifiee $record): string => $record->cron_expression),
                TextColumn::make('etat')
                    ->label('État')
                    ->badge()
                    ->state(fn (TachePlanifiee $record): string => $record->etat())
                    ->color(fn (string $state): string => match ($state) {
                        TachePlanifiee::ECHOUEE => 'danger',
                        TachePlanifiee::EN_RETARD => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('last_started_at')->label('Dernier départ')->dateTime('d.m.Y H:i')->placeholder('jamais'),
                TextColumn::make('last_finished_at')->label('Dernière fin')->dateTime('d.m.Y H:i')->placeholder('jamais'),
                TextColumn::make('last_failed_at')->label('Dernier échec')->dateTime('d.m.Y H:i')->placeholder('aucun'),
                TextColumn::make('grace_time_in_minutes')->label('Marge')->suffix(' min'),
            ])
            ->filters([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
