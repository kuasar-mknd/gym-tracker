<?php

declare(strict_types=1);

namespace App\Filament\Resources\Goals\Tables;

use App\Enums\GoalType;
use App\Filament\Resources\Goals\Schemas\GoalForm;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GoalsTable
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
            TextColumn::make('user.name')->label('Compte')->searchable(),
            TextColumn::make('title')->label('Titre')->searchable(),
            TextColumn::make('type')->label('Type')->badge()
                ->formatStateUsing(fn (GoalType $state): string => GoalForm::TYPES[$state->value] ?? $state->value),
            TextColumn::make('target_value')->label('Valeur cible')->numeric()->sortable(),
            TextColumn::make('current_value')->label('Valeur actuelle')->numeric()->sortable(),
            TextColumn::make('start_value')->label('Valeur de départ')->numeric()->sortable(),
            TextColumn::make('exercise.name')->label('Exercice')->searchable(),
            TextColumn::make('measurement_type')->label('Mensuration')->searchable(),
            TextColumn::make('deadline')->label('Échéance')->date()->sortable(),
            TextColumn::make('completed_at')->label('Atteint le')->dateTime()->sortable(),
            TextColumn::make('created_at')->label('Créé le')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('updated_at')->label('Modifié le')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
        ];
    }
}
