<?php

declare(strict_types=1);

namespace App\Filament\Resources\Goals;

use App\Filament\Resources\Goals\Pages\CreateGoal;
use App\Filament\Resources\Goals\Pages\EditGoal;
use App\Filament\Resources\Goals\Pages\ListGoals;
use App\Filament\Resources\Goals\Schemas\GoalForm;
use App\Filament\Resources\Goals\Tables\GoalsTable;
use App\Models\Goal;
use App\Models\User;
use App\Services\GoalService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

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

    /**
     * L'avancement de l'objectif, que seul son écrivain écrit
     * (`GoalService::syncGoals()`, #1513) : jamais pris dans les données du
     * formulaire, où ses champs sont en lecture seule.
     */
    public const array CHAMPS_CALCULES = ['current_value', 'progress_pct', 'completed_at'];

    /**
     * L'écriture de la création, de la page de modification et de l'action
     * de la table : le compte affecté à la main, le reste par assignation en
     * masse, puis l'avancement recalculé par son écrivain, dans la même
     * transaction (#1513).
     *
     * Le panneau enregistrait ce qui était tapé, valeur actuelle et date
     * d'atteinte comprises, sans recalculer la barre : l'objectif le gardait
     * jusqu'à la prochaine activité du compte. `syncGoals()` relit les
     * objectifs du compte par sa clef, si bien qu'un objectif donné à un autre
     * compte se recalcule sur l'activité de celui-ci. Le compte n'est pas dans
     * le `$fillable` de `Goal` (#1352, `CHAMPS_ASSIGNES_EXPLICITEMENT`).
     *
     * @param  array<mixed>  $data  L'état déshydraté du formulaire.
     */
    public static function enregistrer(Goal $objectif, array $data): Goal
    {
        /*
         * Le champ vient d'un `Select` sur les comptes, il porte un
         * identifiant : `$data` est le tableau deshydrate du formulaire, donc
         * non type.
         */
        /** @var int|string $identifiant */
        $identifiant = $data['user_id'];

        /** @var array<string, mixed> $attributs */
        $attributs = Arr::except($data, [...self::CHAMPS_ASSIGNES_EXPLICITEMENT, ...self::CHAMPS_CALCULES]);

        $objectif->user_id = (int) $identifiant;
        $objectif->fill($attributs);

        DB::transaction(static function () use ($objectif): void {
            $objectif->save();

            app(GoalService::class)->syncGoals(User::query()->findOrFail($objectif->user_id));
        });

        return $objectif->refresh();
    }

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
