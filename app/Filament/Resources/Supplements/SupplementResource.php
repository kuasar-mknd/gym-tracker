<?php

declare(strict_types=1);

namespace App\Filament\Resources\Supplements;

use App\Filament\Resources\Supplements\Pages\CreateSupplement;
use App\Filament\Resources\Supplements\Pages\EditSupplement;
use App\Filament\Resources\Supplements\Pages\ListSupplements;
use App\Filament\Resources\Supplements\Schemas\SupplementForm;
use App\Filament\Resources\Supplements\Tables\SupplementsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SupplementResource extends Resource
{
    #[\Override]
    protected static ?string $modelLabel = 'Supplément';

    #[\Override]
    protected static ?string $pluralModelLabel = 'Suppléments';

    #[\Override]
    protected static ?string $navigationLabel = 'Suppléments';

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    #[\Override]
    protected static \UnitEnum|string|null $navigationGroup = 'Données Utilisateur';

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return SupplementForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return SupplementsTable::configure($table);
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
            'index' => ListSupplements::route('/'),
            'create' => CreateSupplement::route('/create'),
            'edit' => EditSupplement::route('/{record}/edit'),
        ];
    }
}
