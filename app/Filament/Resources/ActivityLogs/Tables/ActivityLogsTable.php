<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityLogs\Tables;

use App\Models\ActivityLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ActivityLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Date')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('log_name')->label('Journal')->badge(),
                TextColumn::make('description')->label('Évènement'),
                TextColumn::make('subject')
                    ->label('Sujet')
                    ->state(fn (ActivityLog $record): string => class_basename((string) $record->subject_type).' #'.$record->subject_id),
                TextColumn::make('causer')
                    ->label('Par')
                    ->state(fn (ActivityLog $record): string => $record->causer_type === null
                        ? 'système'
                        : class_basename((string) $record->causer_type).' #'.$record->causer_id),
                TextColumn::make('properties')
                    ->label('Changements')
                    ->state(fn (ActivityLog $record): string => (string) json_encode($record->properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    ->limit(80)
                    ->tooltip(fn (ActivityLog $record): string => (string) json_encode($record->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ])
            ->filters([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
