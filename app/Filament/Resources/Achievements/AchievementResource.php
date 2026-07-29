<?php

declare(strict_types=1);

namespace App\Filament\Resources\Achievements;

use App\Filament\Resources\Achievements\Pages\CreateAchievement;
use App\Filament\Resources\Achievements\Pages\EditAchievement;
use App\Filament\Resources\Achievements\Pages\ListAchievements;
use App\Filament\Resources\Achievements\Schemas\AchievementForm;
use App\Filament\Resources\Achievements\Tables\AchievementsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AchievementResource extends Resource
{
    #[\Override]
    protected static ?string $modelLabel = 'Badge';

    #[\Override]
    protected static ?string $pluralModelLabel = 'Badges';

    #[\Override]
    protected static ?string $navigationLabel = 'Badges';

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    #[\Override]
    protected static \UnitEnum|string|null $navigationGroup = 'Gestion Contenu';

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return AchievementForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return AchievementsTable::configure($table);
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
            'index' => ListAchievements::route('/'),
            'create' => CreateAchievement::route('/create'),
            'edit' => EditAchievement::route('/{record}/edit'),
        ];
    }
}
