<?php

declare(strict_types=1);

namespace App\Filament\Resources\Goals\Schemas;

use App\Enums\GoalType;
use App\Rules\DateQueSaColonneTient;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
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

    /**
     * L'échéance (`DATE`) et la date d'atteinte (`TIMESTAMP`) se bornent à la
     * plage de leur colonne (`DateQueSaColonneTient`), le titre et la
     * mensuration à leurs 255 caractères : au-delà, la base refusait
     * l'écriture, et l'enregistrement finissait en 500 (#2071).
     *
     * Un objectif de mensuration nomme sa mensuration, comme l'exige
     * `GoalStoreRequest` (`required_if:type,measurement`), que le panneau ne
     * traverse pas : sans elle, l'objectif ne suivait rien, gardait sa valeur
     * actuelle de 0, et sa barre se remplissait dès que sa cible était sous
     * son départ (#2132). Le type est `live()`, pour que le champ se marque
     * obligatoire dès le choix. La création, la page de modification et
     * l'action de la table partagent ce formulaire.
     *
     * @return array<int, \Illuminate\Contracts\Support\Htmlable|string>
     */
    private static function getComponents(): array
    {
        return [
            Select::make('user_id')->label('Compte')->relationship('user', 'name')->searchable()->required(),
            TextInput::make('title')->label('Titre')->required()->maxLength(255),
            Select::make('type')->label('Type')->options(self::TYPES)->required()->live(),
            TextInput::make('target_value')->label('Valeur cible')->required()->numeric(),
            TextInput::make('current_value')->label('Valeur actuelle')->required()->numeric()->default(0),
            TextInput::make('start_value')->label('Valeur de départ')->required()->numeric()->default(0),
            Select::make('exercise_id')->label('Exercice')->relationship('exercise', 'name')->searchable(),
            TextInput::make('measurement_type')->label('Mensuration')->maxLength(255)
                ->required(fn (Get $get): bool => $get('type') === GoalType::Measurement->value),
            DatePicker::make('deadline')->label('Échéance')
                ->minDate(DateQueSaColonneTient::date()->premierJour)
                ->maxDate(DateQueSaColonneTient::date()->dernierJour),
            DateTimePicker::make('completed_at')->label('Atteint le')
                ->minDate(DateQueSaColonneTient::horodatage()->premierInstant())
                ->maxDate(DateQueSaColonneTient::horodatage()->dernierInstant()),
        ];
    }
}
