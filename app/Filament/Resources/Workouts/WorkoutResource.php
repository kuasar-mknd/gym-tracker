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
