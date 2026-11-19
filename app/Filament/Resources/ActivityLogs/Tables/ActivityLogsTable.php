<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityLogs\Tables;

use App\Models\ActivityLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ActivityLogsTable
{
    /**
     * Une entrée sans cause est celle du « système » ; une entrée sans sujet,
     * comme la suppression anonyme d'un compte par un administrateur (#1938),
     * n'en nomme « aucun ».
     */
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
                    ->state(fn (ActivityLog $record): string => $record->subject_type === null
                        ? 'aucun'
                        : class_basename((string) $record->subject_type).' #'.$record->subject_id),
                TextColumn::make('causer')
                    ->label('Par')
                    ->state(fn (ActivityLog $record): string => $record->causer_type === null
                        ? 'système'
                        : class_basename((string) $record->causer_type).' #'.$record->causer_id),
                TextColumn::make('properties')
                    ->label('Changements')
                    ->state(fn (ActivityLog $record): string => (string) json_encode(self::changements($record), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    ->limit(80)
                    ->tooltip(fn (ActivityLog $record): string => (string) json_encode(self::changements($record), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ])
            ->filters([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    /**
     * Depuis spatie/laravel-activitylog 5, les attributs changés vivent dans
     * `attribute_changes` et `properties` ne garde que ce qu'on y ajoute.
     *
     * @return array<mixed>
     */
    private static function changements(ActivityLog $record): array
    {
        return [...($record->properties?->toArray() ?? []), ...($record->attribute_changes?->toArray() ?? [])];
    }
}
