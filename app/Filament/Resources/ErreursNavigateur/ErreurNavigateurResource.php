<?php

declare(strict_types=1);

namespace App\Filament\Resources\ErreursNavigateur;

use App\Filament\Resources\ErreursNavigateur\Pages\ListErreursNavigateur;
use App\Filament\Resources\ErreursNavigateur\Tables\ErreursNavigateurTable;
use App\Models\ErreurNavigateur;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Les erreurs rapportées par les navigateurs, en lecture seule : ce que
 * Sentry recevait, lu ici.
 */
class ErreurNavigateurResource extends Resource
{
    #[\Override]
    protected static ?string $model = ErreurNavigateur::class;

    #[\Override]
    protected static ?string $modelLabel = 'Erreur navigateur';

    #[\Override]
    protected static ?string $pluralModelLabel = 'Erreurs navigateur';

    #[\Override]
    protected static ?string $navigationLabel = 'Erreurs navigateur';

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBugAnt;

    #[\Override]
    protected static \UnitEnum|string|null $navigationGroup = 'Système';

    #[\Override]
    protected static ?int $navigationSort = 92;

    #[\Override]
    public static function table(Table $table): Table
    {
        return ErreursNavigateurTable::configure($table);
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
            'index' => ListErreursNavigateur::route('/'),
        ];
    }
}
