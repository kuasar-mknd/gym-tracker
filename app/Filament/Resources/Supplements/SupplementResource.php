<?php

namespace App\Filament\Resources\Supplements;

use App\Filament\Resources\Supplements\Pages\CreateSupplement;
use App\Filament\Resources\Supplements\Pages\EditSupplement;
use App\Filament\Resources\Supplements\Pages\ListSupplements;
use App\Filament\Resources\Supplements\Schemas\SupplementForm;
use App\Filament\Resources\Supplements\Tables\SupplementsTable;
use App\Models\Supplement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SupplementResource extends Resource
{
    protected static ?string $model = Supplement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static \UnitEnum|string|null $navigationGroup = 'User Data';

    public static function form(Schema $schema): Schema
    {
        return SupplementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SupplementsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplements::route('/'),
            'create' => CreateSupplement::route('/create'),
            'edit' => EditSupplement::route('/{record}/edit'),
        ];
    }
}
