<?php

declare(strict_types=1);

namespace App\Filament\Resources\TachesPlanifiees;

use App\Filament\Resources\TachesPlanifiees\Pages\ListTachesPlanifiees;
use App\Filament\Resources\TachesPlanifiees\Tables\TachesPlanifieesTable;
use App\Models\TachePlanifiee;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Les tâches planifiées et leur dernière exécution, en lecture seule : une
 * tâche qui cesse de tourner ne lève aucune erreur, c'est ici qu'on le voit.
 */
class TachePlanifieeResource extends Resource
{
    #[\Override]
    protected static ?string $model = TachePlanifiee::class;

    #[\Override]
    protected static ?string $slug = 'taches-planifiees';

    #[\Override]
    protected static ?string $modelLabel = 'Tâche planifiée';

    #[\Override]
    protected static ?string $pluralModelLabel = 'Tâches planifiées';

    #[\Override]
    protected static ?string $navigationLabel = 'Tâches planifiées';

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    #[\Override]
    protected static \UnitEnum|string|null $navigationGroup = 'Système';

    #[\Override]
    protected static ?int $navigationSort = 92;

    #[\Override]
    public static function table(Table $table): Table
    {
        return TachesPlanifieesTable::configure($table);
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListTachesPlanifiees::route('/'),
        ];
    }
}
