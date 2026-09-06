<?php

declare(strict_types=1);

namespace App\Filament\Resources\ErreursNavigateur\Tables;

use App\Models\ErreurNavigateur;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ErreursNavigateurTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Date')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('type')->label('Type')->badge(),
                TextColumn::make('message')->label('Message')->limit(80)->tooltip(fn (ErreurNavigateur $record): string => $record->message)->searchable(),
                TextColumn::make('source')
                    ->label('Source')
                    ->state(fn (ErreurNavigateur $record): string => $record->source === null ? '' : $record->source.($record->ligne === null ? '' : ':'.$record->ligne))
                    ->limit(60),
                TextColumn::make('url')->label('Page')->limit(60)->tooltip(fn (ErreurNavigateur $record): string => $record->url),
                TextColumn::make('user.name')->label('Compte')->placeholder('—'),
                TextColumn::make('agent')->label('Navigateur')->limit(40)->tooltip(fn (ErreurNavigateur $record): string => (string) $record->agent)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->options(array_combine(ErreurNavigateur::TYPES, ErreurNavigateur::TYPES)),
            ])
            ->recordActions([
                \Filament\Actions\DeleteAction::make(),
            ])
            ->toolbarActions([]);
    }
}
