<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class UserResource extends Resource
{
    #[\Override]
    protected static ?string $modelLabel = 'Utilisateur';

    #[\Override]
    protected static ?string $pluralModelLabel = 'Utilisateurs';

    #[\Override]
    protected static ?string $navigationLabel = 'Utilisateurs';

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    #[\Override]
    protected static \UnitEnum|string|null $navigationGroup = 'Données Utilisateur';

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
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
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
