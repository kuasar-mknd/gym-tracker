<?php

declare(strict_types=1);

namespace App\Filament\Resources\Supplements\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SupplementsTable
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
        return [
            TextColumn::make('user.name')->searchable(),
            TextColumn::make('name')->searchable(),
            TextColumn::make('brand')->searchable(),
            TextColumn::make('dosage')->searchable(),
            TextColumn::make('servings_remaining')->numeric()->sortable(),
            TextColumn::make('low_stock_threshold')->numeric()->sortable(),
            TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
        ];
    }
}
