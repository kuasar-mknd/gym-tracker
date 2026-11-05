<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workouts\Schemas;

use App\Models\Casts\InstantAvecSonDecalage;
use App\Models\Workout;
use App\Rules\DateQueSaColonneTient;
use Carbon\CarbonInterface;
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
     *
     * Le début et la fin se bornent à la plage de leur colonne
     * (`DateQueSaColonneTient`) : la fin à celle d'un `DATETIME`, le début à
     * celle d'un horodatage, puisqu'il devient la dernière séance du compte
     * (`users.last_workout_at`). Le nom tient dans ses 255 caractères, les
     * notes dans les mille que l'application leur laisse. Au-delà,
     * l'enregistrement finissait en 500 (#2071).
     *
     * Le début et la fin s'écrivent avec leur décalage (#2159) : le champ ne
     * montre que l'heure murale de l'application, que les deux occurrences de
     * l'heure répétée au passage à l'heure d'hiver partagent, et chaque
     * enregistrement, un simple renommage compris, la renvoie. Relue sans
     * décalage, elle replaçait dans la seconde occurrence, une heure plus
     * tard, une séance commencée ou finie dans la première. Une heure repart
     * donc avec le décalage que la séance porte déjà, tant qu'il redonne
     * cette heure (`avecLeDecalageEnregistre()`) : laissée telle quelle, ou
     * changée sans quitter l'occurrence de la séance. Sinon, c'est une heure
     * de l'application, lue dans la seconde occurrence quand elle tombe dans
     * l'heure répétée, comme toute heure sans décalage.
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
                TextInput::make('name')->label('Nom')
                    ->maxLength(255),
                DateTimePicker::make('started_at')->label('Début')
                    ->required()
                    ->minDate(DateQueSaColonneTient::horodatage()->premierInstant())
                    ->maxDate(DateQueSaColonneTient::horodatage()->dernierInstant())
                    ->dehydrateStateUsing(fn (mixed $state, ?Workout $record): mixed => self::avecLeDecalageEnregistre($state, $record?->started_at)),
                DateTimePicker::make('ended_at')->label('Fin')
                    ->minDate(DateQueSaColonneTient::date()->premierInstant())
                    ->maxDate(DateQueSaColonneTient::date()->dernierInstant())
                    ->dehydrateStateUsing(fn (mixed $state, ?Workout $record): mixed => self::avecLeDecalageEnregistre($state, $record?->ended_at)),
                Textarea::make('notes')->label('Notes')
                    ->maxLength(1000)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * L'heure du champ, telle que Filament la rend (l'heure murale de
     * l'application, `Y-m-d H:i:s`), suivie du décalage de l'instant
     * enregistré quand ce décalage redonne cette heure dans le fuseau de
     * l'application, c'est-à-dire quand le cast le retiendrait
     * (`InstantAvecSonDecalage::lire()`). Une autre heure, ou une séance sans
     * cet instant, part telle quelle.
     *
     * Une heure inchangée garde ainsi son occurrence. Une heure changée garde
     * celle de l'instant enregistré tant que son décalage va avec elle : une
     * séance de la première occurrence dont on retouche le début ou la fin
     * dans l'heure répétée y reste. Lue seule, cette heure passait dans la
     * seconde occurrence, à côté de l'autre champ resté dans la première :
     * l'écran montrait le début avant la fin, et la fin s'enregistrait avant
     * le début. Une heure dont le décalage enregistré ne va plus (passée de
     * l'été à l'hiver) se lit seule, comme toute heure sans décalage.
     */
    private static function avecLeDecalageEnregistre(mixed $heure, ?CarbonInterface $enregistre): mixed
    {
        if (! is_string($heure) || ! $enregistre instanceof CarbonInterface) {
            return $heure;
        }

        $enregistre = $enregistre->copy()->setTimezone(config()->string('app.timezone'));
        $decalage = $enregistre->getOffset();
        $lue = InstantAvecSonDecalage::lire($heure, intdiv($decalage, 60));

        return $lue->getOffset() === $decalage
            ? $heure.$enregistre->format('P')
            : $heure;
    }
}
