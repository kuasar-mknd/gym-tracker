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
            /*
             * Montres, jamais ecrits.
             *
             * Ces trois champs sont l'identite du compte : `provider` et
             * `provider_id` sont ce sur quoi la connexion externe s'appuie pour
             * reconnaitre quelqu'un, et `email_verified_at` est ce qui autorise
             * a lier un compte social — `ResolveSocialUserAction` refuse la
             * liaison quand l'adresse n'est pas verifiee, precisement pour
             * empecher une prise de controle.
             *
             * Ils etaient exposes en saisie libre et hors `$fillable` : en
             * production, l'admin voyait une notification de succes et rien
             * n'etait ecrit (#1352). Les elargir au `$fillable` aurait ete pire
             * que le defaut, `$fillable` valant pour TOUS les chemins
             * d'assignation en masse et pas seulement pour le back-office.
             *
             * S'il faut un jour revérifier une adresse ou delier un compte
             * social depuis le back-office, cela demande une `Action` explicite
             * avec sa propre autorisation, pas un champ de formulaire.
             */
            DateTimePicker::make('email_verified_at')->disabled()->dehydrated(false),
            TextInput::make('provider')->disabled()->dehydrated(false),
            TextInput::make('provider_id')->disabled()->dehydrated(false),
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
            /*
             * Trois valeurs DERIVEES, en lecture seule et desormais non
             * deshydratees.
             *
             * `readOnly()` empeche de taper dedans, mais Filament les envoyait
             * quand meme dans les donnees : hors `$fillable`, elles etaient
             * ignorees en silence en production. Et les y ajouter aurait ete un
             * contresens — elles se recalculent depuis les faits
             * (`StreakService::recalculerDepuisLesFaits`), une valeur saisie a
             * la main serait ecrasee a la premiere seance.
             */
            TextInput::make('current_streak')->numeric()->readOnly()->dehydrated(false),
            TextInput::make('longest_streak')->numeric()->readOnly()->dehydrated(false),
            DateTimePicker::make('last_workout_at')->readOnly()->dehydrated(false),
        ];
    }
}
