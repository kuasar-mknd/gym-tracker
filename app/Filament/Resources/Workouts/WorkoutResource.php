<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workouts;

use App\Filament\Resources\Workouts\Pages\CreateWorkout;
use App\Filament\Resources\Workouts\Pages\EditWorkout;
use App\Filament\Resources\Workouts\Pages\ListWorkouts;
use App\Filament\Resources\Workouts\Schemas\WorkoutForm;
use App\Filament\Resources\Workouts\Tables\WorkoutsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WorkoutResource extends Resource
{
    /**
     * Les champs que les pages ecrivent elles-memes, hors assignation en masse.
     *
     * `user_id` a un sens en creation — l'exploitant choisit a qui la ligne
     * appartient — mais il n'a rien a faire dans le `$fillable` du modele :
     * celui-ci vaut pour TOUS les chemins d'assignation en masse, et rien ne
     * doit pouvoir changer le proprietaire d'une ligne depuis une requete
     * utilisateur. La page l'affecte donc explicitement (#1352).
     *
     * @var list<string>
     */
    public const CHAMPS_ASSIGNES_EXPLICITEMENT = ['user_id'];

    #[\Override]
    protected static ?string $modelLabel = 'Séance';

    #[\Override]
    protected static ?string $pluralModelLabel = 'Séances';

    #[\Override]
    protected static ?string $navigationLabel = 'Séances';

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    #[\Override]
    protected static \UnitEnum|string|null $navigationGroup = 'Données Utilisateur';

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return WorkoutForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return WorkoutsTable::configure($table);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListWorkouts::route('/'),
            'create' => CreateWorkout::route('/create'),
            'edit' => EditWorkout::route('/{record}/edit'),
        ];
    }
}
