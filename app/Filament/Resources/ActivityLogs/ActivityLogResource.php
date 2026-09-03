<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityLogs;

use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\ActivityLogs\Tables\ActivityLogsTable;
use App\Models\ActivityLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Le journal d'audit des comptes (User, Admin), en lecture seule : une trace
 * que personne ne peut consulter ne vaut pas ce qu'elle coûte à écrire.
 */
class ActivityLogResource extends Resource
{
    #[\Override]
    protected static ?string $model = ActivityLog::class;

    #[\Override]
    protected static ?string $modelLabel = "Entrée d'audit";

    #[\Override]
    protected static ?string $pluralModelLabel = "Journal d'audit";

    #[\Override]
    protected static ?string $navigationLabel = "Journal d'audit";

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    #[\Override]
    protected static \UnitEnum|string|null $navigationGroup = 'Système';

    #[\Override]
    public static function table(Table $table): Table
    {
        return ActivityLogsTable::configure($table);
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
            'index' => ListActivityLogs::route('/'),
        ];
    }
}
