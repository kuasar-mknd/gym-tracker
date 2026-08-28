<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * « Tout selectionner » couvre la table entiere, pas la page affichee.
             *
             * Filament materialise ensuite le jeu et supprime LIGNE A LIGNE, avec
             * la cascade d'evenements pour chacune. Mesure faite sur un compte de
             * 400 seances : une seule suppression lit ~1 000 lignes, et le cout
             * est lineaire dans l'historique. Cent est donc le plafond qui garde
             * la requete sous les 100 000 lignes lues ; cinq cents l'y mettrait a
             * un demi-million.
             */
            ->maxSelectableRecords(100)
            ->columns(self::getColumns())
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->chunkSelectedRecords(100),
                ]),
            ]);
    }

    /** @return array<\Filament\Tables\Columns\Column> */
    private static function getColumns(): array
    {
        return array_merge(self::getIdentityColumns(), self::getStatsColumns());
    }

    /** @return array<\Filament\Tables\Columns\Column> */
    private static function getIdentityColumns(): array
    {
        return [
            TextColumn::make('name')->searchable(),
            TextColumn::make('email')->label('Email address')->searchable(),
            TextColumn::make('default_rest_time')->numeric()->sortable(),
            TextColumn::make('email_verified_at')->dateTime()->sortable(),
            TextColumn::make('provider')->searchable(),
            TextColumn::make('provider_id')->searchable(),
            TextColumn::make('avatar')->searchable(),
        ];
    }

    /** @return array<\Filament\Tables\Columns\Column> */
    private static function getStatsColumns(): array
    {
        return [
            TextColumn::make('current_streak')->numeric()->sortable(),
            TextColumn::make('longest_streak')->numeric()->sortable(),
            TextColumn::make('last_workout_at')->dateTime()->sortable(),
            TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
        ];
    }
}
