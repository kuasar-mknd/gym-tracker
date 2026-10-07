<?php

declare(strict_types=1);

namespace App\Filament\Resources\Goals\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class GoalForm
{
    /**
     * Les types d'objectif, nommés comme le formulaire de l'application les
     * nomme (`resources/js/Components/Goals/GoalForm.vue`). Le panneau les
     * montrait en anglais (« Weight », « Frequency », « Measurement ») (#1979).
     *
     * @var array<string, string>
     */
    public const array TYPES = [
        'weight' => 'Force (Poids max)',
        'frequency' => 'Fréquence (Séances)',
        'volume' => 'Volume (Max par séance)',
        'measurement' => 'Mensuration',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(self::getComponents());
    }

    /** @return array<int, \Illuminate\Contracts\Support\Htmlable|string> */
    private static function getComponents(): array
    {
        return [
            Select::make('user_id')->label('Compte')->relationship('user', 'name')->searchable()->required(),
            TextInput::make('title')->label('Titre')->required(),
            Select::make('type')->label('Type')->options(self::TYPES)->required(),
            TextInput::make('target_value')->label('Valeur cible')->required()->numeric(),
            TextInput::make('current_value')->label('Valeur actuelle')->required()->numeric()->default(0),
            TextInput::make('start_value')->label('Valeur de départ')->required()->numeric()->default(0),
            Select::make('exercise_id')->label('Exercice')->relationship('exercise', 'name')->searchable(),
            TextInput::make('measurement_type')->label('Mensuration'),
            DatePicker::make('deadline')->label('Échéance'),
            DateTimePicker::make('completed_at')->label('Atteint le'),
        ];
    }
}
