<?php

declare(strict_types=1);

namespace App\Filament\Resources\Goals\Schemas;

use App\Enums\GoalPeriod;
use App\Enums\GoalType;
use App\Http\Controllers\GoalController;
use App\Models\Exercise;
use App\Rules\CibleAuMoinsEgaleAuDepart;
use App\Rules\CibleEnSeancesParSemaine;
use App\Rules\DateQueSaColonneTient;
use App\Services\Diagnostic\ValeurLue;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
use Illuminate\Database\Eloquent\Builder;

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

    /**
     * Les périodes d'un objectif de fréquence, nommées comme le formulaire de
     * l'application (`GoalForm.vue`).
     *
     * @var array<string, string>
     */
    public const array PERIODES = [
        'total' => 'Au total',
        'week' => 'Par semaine (du lundi au dimanche)',
    ];

    /**
     * Ce que le panneau dit sous les deux champs calculés.
     */
    private const string AVANCEMENT_CALCULE = 'Se recalcule depuis l’activité du compte, à chaque enregistrement.';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(self::getComponents());
    }

    /**
     * L'échéance (`DATE`) se borne à la plage de sa colonne
     * (`DateQueSaColonneTient`), le titre à ses 255 caractères : au-delà, la
     * base refusait l'écriture, et l'enregistrement finissait en 500 (#2071).
     * La mensuration se choisit dans une liste.
     *
     * Un objectif de mensuration nomme sa mensuration, comme l'exige
     * `GoalStoreRequest` (`required_if:type,measurement`), que le panneau ne
     * traverse pas : sans elle, l'objectif ne suivait rien, gardait sa valeur
     * actuelle de 0, et sa barre se remplissait dès que sa cible était sous
     * son départ (#2132). Le type est `live()`, pour que le champ se marque
     * obligatoire dès le choix. La création, la page de modification et
     * l'action de la table partagent ce formulaire.
     *
     * Le panneau exige aussi le reste de ce que la requête exige (#2164) :
     * l'exercice d'un objectif de charge ou de volume, sans lequel l'objectif
     * ne suit rien ; une mensuration prise dans la liste de l'application, sous
     * son nom français (un nom qu'aucune mesure ne porte, « Taille » pour
     * `Waist`, revenait à son départ et n'en bougeait plus) ; et, hors
     * mensuration, une cible au moins égale au départ, que la règle partagée
     * compare au départ saisi, à l'envoi.
     *
     * L'exercice se choisit parmi ceux du compte de l'objectif (#2166) : le
     * compte est `live()`, et les options suivent `exercicesDuCompte()`.
     *
     * La valeur actuelle et la date d'atteinte se calculent, elles ne se
     * tapent pas (#1513) : en lecture seule à la modification, absentes à la
     * création, elles ne partent pas avec le formulaire, et l'enregistrement
     * recalcule l'objectif par son écrivain (`GoalResource::enregistrer()`).
     * Tapées ici, elles tenaient jusqu'à la prochaine activité du compte, sans
     * que la barre suive, et une date d'atteinte tapée sur un objectif atteint
     * restait pour de bon. L'échéance, elle, est une saisie.
     *
     * @return array<int, \Illuminate\Contracts\Support\Htmlable|string>
     */
    private static function getComponents(): array
    {
        return [
            Select::make('user_id')->label('Compte')->relationship('user', 'name')->searchable()->required()->live(),
            TextInput::make('title')->label('Titre')->required()->maxLength(255),
            Select::make('type')->label('Type')->options(self::TYPES)->required()->live(),
            Select::make('period')->label('Période')->options(self::PERIODES)->default(GoalPeriod::Total->value)
                ->required(fn (Get $get): bool => $get('type') === GoalType::Frequency->value)
                ->visible(fn (Get $get): bool => $get('type') === GoalType::Frequency->value)
                ->live()
                ->helperText('« Par semaine » compte les séances faites du lundi au dimanche ; la valeur de départ ne sert pas.'),
            TextInput::make('target_value')->label('Valeur cible')->required()->numeric()
                ->rule(
                    fn (Get $get): CibleAuMoinsEgaleAuDepart => new CibleAuMoinsEgaleAuDepart($get('start_value')),
                    fn (Get $get): bool => $get('type') !== GoalType::Measurement->value && ! self::estHebdomadaire($get),
                )
                ->rule(
                    new CibleEnSeancesParSemaine(),
                    fn (Get $get): bool => self::estHebdomadaire($get),
                ),
            TextInput::make('current_value')->label('Valeur actuelle')->numeric()->disabled()->hiddenOn(Operation::Create)
                ->helperText(self::AVANCEMENT_CALCULE),
            TextInput::make('start_value')->label('Valeur de départ')->required()->numeric()->default(0)
                ->visible(fn (Get $get): bool => ! self::estHebdomadaire($get)),
            Select::make('exercise_id')->label('Exercice')
                ->relationship('exercise', 'name', modifyQueryUsing: fn (Builder $query, Get $get): Builder => self::exercicesDuCompte($query, $get('user_id')))
                ->searchable()
                ->required(fn (Get $get): bool => in_array($get('type'), [GoalType::Weight->value, GoalType::Volume->value], true)),
            Select::make('measurement_type')->label('Mensuration')
                ->options(array_column(GoalController::measurementTypes(), 'label', 'value'))
                ->required(fn (Get $get): bool => $get('type') === GoalType::Measurement->value),
            DatePicker::make('deadline')->label('Échéance')
                ->minDate(DateQueSaColonneTient::date()->premierJour)
                ->maxDate(DateQueSaColonneTient::date()->dernierJour),
            DateTimePicker::make('completed_at')->label('Atteint le')->disabled()->hiddenOn(Operation::Create)
                ->helperText(self::AVANCEMENT_CALCULE),
        ];
    }

    /**
     * Un objectif de fréquence « par semaine » : sa cible se juge par
     * `CibleEnSeancesParSemaine`, comme à `GoalStoreRequest`, et son départ ne se
     * tape pas ni ne se compare à la cible (il se lit zéro).
     */
    private static function estHebdomadaire(Get $get): bool
    {
        return $get('type') === GoalType::Frequency->value && $get('period') === GoalPeriod::Week->value;
    }

    /**
     * Les exercices qu'un objectif du compte peut suivre : le catalogue et les
     * exercices personnels du compte, la portée qui borne déjà la requête de
     * l'application (`Exercise::scopeForUser()`, `GoalStoreRequest`). Sans
     * compte choisi, le catalogue seul.
     *
     * Le panneau proposait les exercices de tous les comptes, et rattachait
     * sans refus un objectif à l'exercice personnel d'un autre : le recalcul
     * lit les records du compte de l'objectif, qu'il n'a pas sur cet exercice,
     * et l'objectif ne suivait rien (#2166). Le `Select` ne retrouve que ce que
     * cette requête rend : un autre exercice est refusé à l'enregistrement
     * (règle `in`), y compris quand seul le compte change. Le compte se lit
     * par `ValeurLue::entier()`, jamais par `(int)` (#2071).
     *
     * @param  Builder<Exercise>  $query
     * @return Builder<Exercise>
     */
    private static function exercicesDuCompte(Builder $query, mixed $compte): Builder
    {
        $identifiant = ValeurLue::entier($compte);

        return $identifiant === null ? $query->whereNull('user_id') : $query->forUser($identifiant);
    }
}
