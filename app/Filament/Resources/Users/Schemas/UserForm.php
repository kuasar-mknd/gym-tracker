<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(self::getComponents());
    }

    /** @return array<int, \Illuminate\Contracts\Support\Htmlable|string> */
    private static function getComponents(): array
    {
        return [
            TextInput::make('name')->required(),
            TextInput::make('email')->label('Email address')->email()->required(),
            TextInput::make('default_rest_time')->required()->numeric()->default(90),
            DateTimePicker::make('email_verified_at'),
            TextInput::make('provider'),
            TextInput::make('provider_id'),
            TextInput::make('avatar'),
            /*
             * Le champ ne s'ecrit que lorsqu'il porte quelque chose.
             *
             * Sans ce garde, enregistrer une edition effacait le mot de passe
             * de la cible. Filament remplit le formulaire depuis
             * `attributesToArray()`, qui exclut `$hidden` — et `password` y
             * est. La cle etant absente des donnees de remplissage, le champ
             * etait repose a null, deshydrate, puis ecrit : `password` est dans
             * `$fillable`, le cast `hashed` rend null pour null, et la colonne
             * est nullable. `UPDATE users SET password = NULL`, avec une
             * notification de succes et un compte qui ne peut plus se connecter.
             *
             * Invisible hors production, ou le mode strict fait lever sur les
             * autres champs du meme formulaire avant d'arriver ici (#1438).
             */
            TextInput::make('password')
                ->password()
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->required(fn (string $operation): bool => $operation === 'create'),
            TextInput::make('current_streak')->required()->numeric()->default(0)->readOnly(),
            TextInput::make('longest_streak')->required()->numeric()->default(0)->readOnly(),
            DateTimePicker::make('last_workout_at')->readOnly(),
        ];
    }
}
