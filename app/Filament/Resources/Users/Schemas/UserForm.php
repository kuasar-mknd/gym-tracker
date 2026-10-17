<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Exercise;
use App\Models\User;
use App\Rules\AdresseEnAsciiImprimable;
use App\Rules\AdresseQueLaMessagerieSaitEcrire;
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

    /**
     * Chaque champ écrit tient dans sa colonne (#2071) : les textes dans
     * leurs 255 caractères, le repos sous `Exercise::REPOS_MAX_SECONDES`.
     * Sans borne, un texte trop long ou un nombre plus grand qu'un entier
     * finissaient en 500 à l'enregistrement.
     *
     * @return array<int, \Illuminate\Contracts\Support\Htmlable|string>
     */
    private static function getComponents(): array
    {
        return [
            TextInput::make('name')->label('Nom')->required()->maxLength(255),
            /*
             * Une nouvelle adresse s'écrit en ASCII imprimable, comme à
             * l'inscription et dans le profil. La colonne et son index unique
             * confondent une adresse accentuée, un « ß » ou une lettre pleine
             * chasse avec l'adresse ASCII voisine : un compte créé ou modifié
             * ici sur l'une occuperait l'autre, et son titulaire ne pourrait
             * plus s'inscrire. La messagerie doit aussi l'écrire telle quelle :
             * sans quoi aucun courriel n'atteindrait le compte, lien « mot de
             * passe oublié » compris (`AdresseQueLaMessagerieSaitEcrire`, #2075).
             * L'adresse actuelle du compte reste admise telle quelle par les
             * deux règles, pour qu'un compte ancien s'enregistre sans en
             * changer.
             *
             * La forme (règle `email`) ne se juge, de même, que d'une adresse
             * qui change : la connexion sociale créait, avant #2075, le compte
             * de toute adresse en ASCII imprimable (points consécutifs, tiret
             * bas dans le domaine…), dont le nom ne s'enregistrait plus ici.
             * D'où une règle conditionnelle plutôt que `->email()`, qui pose
             * aussi `type="email"` : le navigateur, qui juge l'adresse selon la
             * spécification HTML, refusait d'envoyer le formulaire d'un compte
             * dont l'adresse porte un commentaire, un tiret bas dans le domaine
             * ou un point final. Le champ garde le clavier d'une adresse
             * (`inputmode`).
             */
            TextInput::make('email')
                ->label('Adresse e-mail')
                ->inputMode('email')
                ->required()
                ->maxLength(255)
                ->rule('email', static fn (?User $record, mixed $state): bool => $state !== $record?->email)
                ->rule(fn (?User $record): AdresseEnAsciiImprimable => new AdresseEnAsciiImprimable($record?->email))
                ->rule(fn (?User $record): AdresseQueLaMessagerieSaitEcrire => new AdresseQueLaMessagerieSaitEcrire($record?->email)),
            TextInput::make('default_rest_time')->label('Repos par défaut (s)')->required()->integer()->minValue(0)->maxValue(Exercise::REPOS_MAX_SECONDES)->default(90),
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
            DateTimePicker::make('email_verified_at')->label('Adresse vérifiée le')->disabled()->dehydrated(false),
            /*
             * Un compte ouvert par l'inscription attend la preuve de son
             * adresse (`AttendLaPreuveDeSonAdresse`). Le lien d'ouverture ne
             * fait que marquer la session du navigateur qui le suit : c'est la
             * connexion qui suit, dans ce navigateur, qui ouvre le compte
             * (`ConnecterLeCompte`). La fiche le dit, sans rien écrire : seule
             * la preuve de l'adresse efface l'attente.
             */
            DateTimePicker::make('en_attente_depuis')
                ->label('En attente du lien depuis')
                ->helperText(sprintf("Le compte s'ouvre quand son titulaire prouve son adresse : lien d'ouverture suivi puis connexion dans le même navigateur (le lien seul n'ouvre rien), mot de passe réinitialisé, ou connexion par un fournisseur qui garantit l'adresse. Jamais ouvert, il est effacé au bout de %d jours.", User::JOURS_AVANT_LA_PURGE))
                ->visible(fn (?User $record): bool => $record?->estEnAttente() ?? false)
                ->disabled()
                ->dehydrated(false),
            TextInput::make('provider')->label('Fournisseur de connexion')->disabled()->dehydrated(false),
            TextInput::make('provider_id')->label('Identifiant chez le fournisseur')->disabled()->dehydrated(false),
            TextInput::make('avatar')->label('Avatar')->maxLength(255),
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
             *
             * Et il demande un nouveau mot de passe (`new-password`) : c'est
             * celui d'un autre compte que l'administrateur fixe. Sans le dire,
             * un champ de mot de passe sous une adresse se lit comme un
             * formulaire de connexion, et un gestionnaire de mots de passe y
             * proposait celui de l'administrateur (#2076). Il ne reçoit pas
             * l'identifiant de l'administrateur, que le formulaire du mot de
             * passe du profil donne : ce mot de passe n'est pas le sien.
             */
            TextInput::make('password')->label('Mot de passe')
                ->password()
                ->autocomplete('new-password')
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
            TextInput::make('current_streak')->label('Série en cours')->numeric()->readOnly()->dehydrated(false),
            TextInput::make('longest_streak')->label('Plus longue série')->numeric()->readOnly()->dehydrated(false),
            DateTimePicker::make('last_workout_at')->label('Dernière séance')->readOnly()->dehydrated(false),
        ];
    }
}
