<?php

declare(strict_types=1);

namespace App\Filament\Resources\Goals;

use App\Filament\Resources\Goals\Pages\CreateGoal;
use App\Filament\Resources\Goals\Pages\EditGoal;
use App\Filament\Resources\Goals\Pages\ListGoals;
use App\Filament\Resources\Goals\Schemas\GoalForm;
use App\Filament\Resources\Goals\Tables\GoalsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GoalResource extends Resource
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
    protected static ?string $modelLabel = 'Objectif';

    #[\Override]
    protected static ?string $pluralModelLabel = 'Objectifs';

    #[\Override]
    protected static ?string $navigationLabel = 'Objectifs';

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    #[\Override]
    protected static \UnitEnum|string|null $navigationGroup = 'Données Utilisateur';

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return GoalForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return GoalsTable::configure($table);
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
            'index' => ListGoals::route('/'),
            'create' => CreateGoal::route('/create'),
            'edit' => EditGoal::route('/{record}/edit'),
        ];
    }
}
