<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workouts\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;

class WorkoutForm
{
    /**
     * Le formulaire d'une séance : le propriétaire se choisit à la création, puis se lit seulement.
     *
     * Changer le propriétaire d'une séance existante laissait ses lignes et ses
     * séries à l'ancien compte (`workout_lines.user_id`, `sets.user_id`), et ni
     * la série de jours ni les records des deux comptes n'étaient recalculés
     * (#1933). Une séance n'a pas de raison de changer de compte : le champ est
     * désactivé à la modification plutôt que masqué, pour que l'exploitant voie
     * toujours à qui elle appartient.
     *
     * Désactivé, le champ n'est plus enregistré : `disabled()` le marque non
     * sauvegardé, `Select::relationship()` ne le déshydrate qu'à cette
     * condition, et `saveRelationships()` saute alors le `associate()` qui
     * aurait réécrit la clef. Une requête Livewire forgée qui change sa valeur
     * ne va donc nulle part ; le modèle refuse de toute façon le changement
     * (`Workout::booted()`).
     *
     * La désactivation vit ici, et non dans `EditWorkout` : l'action de
     * modification de la table des séances partage ce formulaire et enregistre
     * par `$record->update($data)`, sans passer par la page.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')->label('Compte')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->required()
                    ->disabledOn(Operation::Edit),
                TextInput::make('name')->label('Nom'),
                DateTimePicker::make('started_at')->label('Début')
                    ->required(),
                DateTimePicker::make('ended_at')->label('Fin'),
                Textarea::make('notes')->label('Notes')
                    ->columnSpanFull(),
            ]);
    }
}
