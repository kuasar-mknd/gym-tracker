<?php

declare(strict_types=1);

namespace App\Filament\Resources\Exercises;

use App\Filament\Resources\Exercises\Pages\CreateExercise;
use App\Filament\Resources\Exercises\Pages\EditExercise;
use App\Filament\Resources\Exercises\Pages\ListExercises;
use App\Filament\Resources\Exercises\Schemas\ExerciseForm;
use App\Filament\Resources\Exercises\Tables\ExercisesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ExerciseResource extends Resource
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
    protected static ?string $modelLabel = 'Exercice';

    #[\Override]
    protected static ?string $pluralModelLabel = 'Exercices';

    #[\Override]
    protected static ?string $navigationLabel = 'Exercices';

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    #[\Override]
    protected static \UnitEnum|string|null $navigationGroup = 'Gestion Contenu';

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return ExerciseForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return ExercisesTable::configure($table);
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
            'index' => ListExercises::route('/'),
            'create' => CreateExercise::route('/create'),
            'edit' => EditExercise::route('/{record}/edit'),
        ];
    }
}
