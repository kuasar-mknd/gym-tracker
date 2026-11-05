<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\AttendLaPreuveDeSonAdresse;
use App\Models\Traits\DetacheSesAppareilsPush;
use App\Models\Traits\EffaceSesTracesPolymorphes;
use App\Models\Traits\HasFitnessData;
use App\Models\Traits\HasToolsData;
use App\Models\Traits\OublieLaPreuveDeSaLiaison;
use App\Models\Traits\SurveilleSonAdresse;
use App\Notifications\LienDOuvertureDuCompte;
use App\Rules\AdresseQueLaMessagerieSaitEcrire;
use App\Support\AdresseCanonique;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification as Notification;
use Illuminate\Notifications\DatabaseNotificationCollection as NotifColl;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $en_attente_depuis Depuis quand le compte ouvert par l'inscription attend la preuve de son adresse ; effacée par toute preuve (`AttendLaPreuveDeSonAdresse`).
 * @property string|null $ancienne_adresse_verifiee Dernière adresse vérifiée quittée, tant que le compte n'est pas revérifié (`SurveilleSonAdresse`).
 * @property string|null $provider
 * @property string|null $provider_id
 * @property \Illuminate\Support\Carbon|null $liaison_prouvee_le Quand la liaison au fournisseur a été prouvée sur l'adresse exacte du compte ; effacée quand l'adresse change (`OublieLaPreuveDeSaLiaison`).
 * @property string|null $avatar
 * @property int|null $default_rest_time
 * @property int $current_streak
 * @property int $longest_streak
 * @property \Illuminate\Support\Carbon|null $last_workout_at
 * @property-read Collection<int, Workout> $workouts
 * @property-read NotifColl<int, Notification> $notifications
 * @property-read NotifColl<int, Notification> $unreadNotifications
 */
final class User extends Authenticatable implements MustVerifyEmail
{
    use AttendLaPreuveDeSonAdresse;
    use DetacheSesAppareilsPush;
    use EffaceSesTracesPolymorphes;
    use HasApiTokens;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    use HasFitnessData;
    use HasPushSubscriptions;
    use HasToolsData;
    use LogsActivity;
    use Notifiable;
    use OublieLaPreuveDeSaLiaison;
    use Prunable;
    use SurveilleSonAdresse;

    /**
     * Les jours qu'un compte ouvert par l'inscription attend la preuve de son
     * adresse avant d'être effacé (`prunable()`). Le lien d'ouverture le dit.
     */
    public const int JOURS_AVANT_LA_PURGE = 7;

    /**
     * Une adresse que la spécification HTML admet dans un champ
     * `type="email"` (« valid e-mail address ») : ni partie locale entre
     * guillemets, ni commentaire, ni domaine littéral, et des labels de
     * lettres, de chiffres et de tirets (`leNavigateurLaisseraitSonDomaineEnUnicode()`).
     */
    private const string ADRESSE_QUE_LA_SPECIFICATION_HTML_ADMET = "/^[a-zA-Z0-9.!#$%&'*+\\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/D";

    /**
     * La valeur en memoire d'une instance fraiche, alignee sur celle de la base.
     *
     * Sans elle, un utilisateur qui vient d'etre cree n'a PAS l'attribut :
     * Eloquent ne relit pas les defauts apres l'insertion. `shouldBeStrict`
     * transforme alors la lecture faite par `HandleInertiaRequests` en erreur
     * hors production, et en `null` silencieux en production.
     *
     * @var array<string, mixed>
     */
    #[\Override]
    protected $attributes = [
        'auto_rest_timer' => true,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    #[\Override]
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'default_rest_time',
        'auto_rest_timer',
    ];

    /**
     * @var list<string>
     */
    #[\Override]
    protected $hidden = [
        'password',
        'remember_token',
        'ancienne_adresse_verifiee',
    ];

    /**
     * Les notifications, du plus recent au plus ancien — et a egalite, par
     * identifiant.
     *
     * `created_at` seul ne suffit pas a ordonner : plusieurs notifications
     * peuvent naitre dans la meme seconde — un record en envoie trois — et
     * MySQL rend alors ce qu'il veut. Une meme notification pouvait donc
     * apparaitre sur deux pages pendant qu'une autre n'apparaissait sur aucune.
     *
     * Le departage est pose sur la RELATION et non sur la page : c'est une
     * propriete de la collection, et tout ce qui la lit doit s'accorder. Elle
     * vient d'un trait, donc la redefinir ici suffit a la remplacer.
     *
     * Le departage descend, comme le tri principal : `created_at DESC, id ASC`
     * melange les sens, et MySQL ne peut plus parcourir l'index d'un seul tenant
     * — mesure, 102 lectures d'index contre 502.
     *
     * @return MorphMany<Notification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'notifiable')
            ->latest()
            ->orderByDesc('id');
    }

    /**
     * @return HasMany<NotificationPreference, $this>
     */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function notificationActivee(string $type): bool
    {
        if ($this->relationLoaded('notificationPreferences')) {
            return $this->notificationPreferences
                ->where('type', $type)
                ->where('is_enabled', true)
                ->isNotEmpty();
        }

        return (bool) $this->notificationPreferences()
            ->where('type', $type)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Faut-il proposer les notifications à la fin d'une séance (#1848) ?
     *
     * Rien ne les proposait hors du profil. La fin d'une séance est le moment
     * où elles ont un sens : un record à annoncer, plutôt qu'une demande à
     * froid au premier écran.
     *
     * Le serveur ne répond que pour le COMPTE : aucun abonnement tenu, et les
     * records pas coupés dans le profil — l'activation les ferait partir en
     * push sans y toucher, donc n'enverrait rien. Ce que tient l'appareil
     * (permission, abonnement, refus mémorisé), seul le navigateur le sait :
     * `useInvitationAuxNotifications` le juge à son tour. Une seule réponse
     * pour la fin par la page (donnée flash) et pour la fin par l'API
     * (`meta.proposer_les_notifications`, #2049).
     */
    public function doitRecevoirLInvitationAuxNotifications(): bool
    {
        return ! $this->pushSubscriptions()->exists()
            && ! $this->notificationPreferences()
                ->where('type', 'personal_record')
                ->where('is_enabled', false)
                ->exists();
    }

    public function notificationsPoussesActivees(string $type): bool
    {
        if ($this->relationLoaded('notificationPreferences')) {
            return $this->notificationPreferences
                ->where('type', $type)
                ->where('is_push_enabled', true)
                ->isNotEmpty();
        }

        return (bool) $this->notificationPreferences()
            ->where('type', $type)
            ->where('is_push_enabled', true)
            ->exists();
    }

    #[\Override]
    protected static function booted(): void
    {
        self::surveillerLAdresse();
    }

    /**
     * Les comptes ouverts par l'inscription dont l'adresse n'a jamais été
     * prouvée, au-delà de `JOURS_AVANT_LA_PURGE` jours : effacés chaque nuit
     * par `model:prune` (`routes/console.php`), un à un, par `delete()`, qui
     * efface aussi leurs traces (`EffaceSesTracesPolymorphes`). Un compte
     * vérifié n'en est jamais, même si son attente restait posée.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()
            ->where('en_attente_depuis', '<', now()->subDays(self::JOURS_AVANT_LA_PURGE))
            ->whereNull('email_verified_at');
    }

    /**
     * Un compte en attente reçoit le lien qui l'ouvre, mené à la connexion
     * (`LienDOuvertureDuCompte`) ; tout autre compte non vérifié, celui de
     * Laravel, qui vérifie l'adresse depuis une session ouverte.
     *
     * Rien ne part à une adresse que la messagerie n'écrit pas telle quelle,
     * qu'un compte d'avant #2075 peut porter
     * (`AdresseQueLaMessagerieSaitEcrire`) : le lien n'y parviendrait pas, et
     * partirait même à une autre adresse si elle porte un chevron entre
     * guillemets. Celui de Laravel, qui n'est pas mis en
     * file, faisait répondre 500 à la demande d'un nouveau lien ; celui d'un
     * compte en attente laissait un job en échec à chaque bon mot de passe.
     * Le contrôleur du renvoi le dit et renvoie au profil, où changer
     * d'adresse (`EmailVerificationNotificationController`).
     */
    #[\Override]
    public function sendEmailVerificationNotification(): void
    {
        if (! $this->laMessagerieSaitEcrireSonAdresse()) {
            return;
        }

        if ($this->estEnAttente()) {
            $this->notify(new LienDOuvertureDuCompte());

            return;
        }

        parent::sendEmailVerificationNotification();
    }

    /**
     * La messagerie écrit-elle l'adresse du compte comme destinataire, telle
     * quelle ?
     *
     * Non pour certaines adresses d'avant #2075 : un commentaire dans la
     * partie locale, un chevron dans une partie locale entre guillemets, ou,
     * pour un compte créé par la connexion sociale, des points consécutifs ou
     * un point final. Aucun courriel ne leur parvient.
     */
    public function laMessagerieSaitEcrireSonAdresse(): bool
    {
        return AdresseQueLaMessagerieSaitEcrire::laMessagerieSaitLEcrire($this->getEmailForVerification());
    }

    /**
     * Le lien « mot de passe oublié » parvient-il à ce compte, et le
     * réinitialise-t-il ?
     *
     * Il faut deux choses. La messagerie écrit son adresse telle quelle
     * (`laMessagerieSaitEcrireSonAdresse()`), sans quoi sa route est vide et
     * rien ne part. La demande admet l'adresse, que la personne saisit : elle
     * la juge par la règle `email` (`PasswordResetLinkRequest`), comme la
     * réinitialisation qui suit (`NewPasswordRequest`). Cette règle refuse des
     * adresses que la messagerie écrit et qu'un compte créé par la connexion
     * sociale avant #2075 peut porter, comme un tiret bas dans le domaine :
     * la page répond par l'erreur du format, et aucun lien ne part. Le chemin
     * vers un mot de passe ne promet le lien qu'à un compte qui le reçoit
     * (`DonneLeCheminVersUnMotDePasse`, `ProfileController::edit`).
     *
     * La demande ne juge pas l'adresse telle qu'elle est tapée : `TrimStrings`
     * la coupe d'abord de ses blancs Unicode, espace insécable comprise
     * (`Str::trim()`), et le courtier cherche le compte sous l'adresse coupée,
     * selon la collation de `users.email`. Un compte d'avant #2001 peut porter
     * un tel blanc, que la messagerie écrit et que la règle admet. En tête,
     * l'adresse coupée ne retrouve pas le compte ; au bout, elle le retrouve
     * quand la collation néglige le blanc (une espace insécable), pas
     * autrement (un remplissage hangul). Le prédicat le demande donc à la
     * base, sur ce seul compte, quand la coupe ou la forme canonique (plus
     * bas) change l'adresse, et seulement alors.
     *
     * La personne tape l'adresse que le profil lui montre dans un champ
     * `type="email"`, à « mot de passe oublié » comme à la réinitialisation
     * et à la connexion, et la demande écrit l'adresse reçue sous sa forme
     * canonique (`AdresseCanonique`, #2168) avant de chercher le compte :
     * un domaine internationalisé, reçu en Unicode ou en Punycode, se cherche
     * en ASCII, la forme que l'inscription enregistre depuis #2001. Chromium
     * convertit en Punycode un domaine qui n'est pas en ASCII sous une
     * adresse que la spécification HTML admet, donc sous une partie locale
     * en ASCII qui n'est pas entre guillemets (« bücher » devient
     * « xn--bcher-kva », mesuré sous Chromium 141), et montre au profil en
     * Unicode un domaine enregistré en Punycode, qu'il ne reconvertit à
     * l'envoi que sous une telle adresse : la demande reçoit alors l'Unicode
     * (`ceQueLaDemandeRecevrait()`), et le reconvertit elle-même, sauf sous un
     * commentaire, que la conversion refuse (règles STD3). Un compte resté en
     * Unicode, que la migration de #2168 laisse quand sa forme ASCII est
     * prise, ne se retrouve pas sous l'ASCII cherché : le prédicat ne lui
     * promet pas le lien, et le chemin le renvoie à un administrateur
     * (#2104). Il ne promet pas davantage le lien à un domaine
     * hors ASCII sous une partie locale en ASCII, que Chromium envoie sous sa
     * propre conversion : « strasse » d'un « straße », un ASCII ordinaire
     * d'une lettre pleine chasse, sous lesquels la collation retrouverait
     * peut-être le compte (mesuré), ou le Punycode d'un « ς », que la forme
     * canonique laisse aussi tel quel et sous lequel la base ne le retrouve
     * pas (`AdresseQueLaMessagerieSaitEcrireTest` en tient le témoin). Dans
     * le doute, il tait un lien qui part plutôt que d'en promettre un qui ne
     * part pas. D'une partie locale qui
     * n'est pas en ASCII, Chromium envoie l'adresse telle qu'elle est tapée,
     * domaine compris, et la demande en convertit le domaine.
     *
     * La règle est recopiée de la demande, pas lue sur elle : la comparaison
     * avec ce que fait vraiment « mot de passe oublié », pour chaque forme
     * d'adresse, vit dans `AdresseQueLaMessagerieSaitEcrireTest`.
     */
    public function peutRecevoirLeLienDeReinitialisation(): bool
    {
        $adresse = $this->getEmailForPasswordReset();
        $adresseCoupee = Str::trim($adresse);
        $adresseRecueParLaDemande = self::ceQueLaDemandeRecevrait($adresseCoupee);
        $adresseCherchee = AdresseCanonique::de($adresseRecueParLaDemande);

        return $this->laMessagerieSaitEcrireSonAdresse()
            && ! self::leNavigateurConvertiraitSonDomaine($adresseCoupee)
            && Validator::make(['email' => $adresseRecueParLaDemande], ['email' => ['required', 'email']])->passes()
            && ($adresseCherchee === $adresse
                || self::query()->whereKey($this->getKey())->where('email', $adresseCherchee)->exists());
    }

    /**
     * Un champ `type="email"` de Chromium enverrait-il cette adresse sous un
     * autre domaine que le sien ? Il convertit en Punycode un domaine qui
     * n'est pas en ASCII, et ne garde la conversion que si elle donne une
     * adresse que la spécification HTML admet, donc sous une partie locale
     * en ASCII. Le prédicat le suppose de toute partie locale en ASCII, entre
     * guillemets comprise : dans le doute, il tait le lien
     * (`peutRecevoirLeLienDeReinitialisation()`).
     */
    private static function leNavigateurConvertiraitSonDomaine(string $adresse): bool
    {
        return ! Str::isAscii(Str::afterLast($adresse, '@')) && Str::isAscii(Str::beforeLast($adresse, '@'));
    }

    /**
     * Ce que la demande reçoit quand la personne tape l'adresse que le profil
     * montre : le domaine en Unicode quand le champ `type="email"` de Chromium
     * le laisserait ainsi (`leNavigateurLaisseraitSonDomaineEnUnicode()`),
     * l'adresse telle quelle sinon. Chromium montre en Unicode un domaine en
     * Punycode, le décodant comme UTS #46 ; une adresse que ce décodage
     * refuse se montre, et part, telle quelle, comme une adresse qui finit
     * par un @ (un domaine vide, que `idn_to_utf8()` refuse par une erreur).
     */
    private static function ceQueLaDemandeRecevrait(string $adresse): string
    {
        if (! self::leNavigateurLaisseraitSonDomaineEnUnicode($adresse)) {
            return $adresse;
        }

        $arobase = (int) strrpos($adresse, '@');
        $domaineEnPunycode = substr($adresse, $arobase + 1);

        if ($domaineEnPunycode === '') {
            return $adresse;
        }

        $details = [];
        $domaine = idn_to_utf8($domaineEnPunycode, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46, $details);

        if (! is_string($domaine) || ! is_array($details) || ($details['errors'] ?? null) !== 0) {
            return $adresse;
        }

        return substr($adresse, 0, $arobase + 1).$domaine;
    }

    /**
     * Le champ `type="email"` du profil montrerait-il le domaine de cette
     * adresse en Unicode, sans que celui de « mot de passe oublié » le rende
     * en Punycode ? Chromium montre en Unicode un domaine en Punycode
     * (« xn--bcher-kva » devient « bücher »), et ne le reconvertit à l'envoi
     * que si l'adresse obtenue est une adresse que la spécification HTML
     * admet : ni une partie locale entre guillemets, ni un commentaire
     * (mesuré sous Chromium 141). La demande reçoit alors l'Unicode. Le
     * prédicat le suppose de tout « xn-- » qui suit le premier @, casse
     * ignorée, quand l'adresse n'est pas une adresse HTML valide.
     */
    private static function leNavigateurLaisseraitSonDomaineEnUnicode(string $adresse): bool
    {
        return Str::contains(Str::after($adresse, '@'), 'xn--', ignoreCase: true)
            && preg_match(self::ADRESSE_QUE_LA_SPECIFICATION_HTML_ADMET, $adresse) !== 1;
    }

    /**
     * L'adresse à laquelle part un courriel du compte, ou null quand la
     * messagerie ne l'écrit pas telle quelle (décision du propriétaire,
     * #2075).
     *
     * Le canal `mail` des notifications n'envoie rien, sans erreur, à un
     * destinataire null (`MailChannel::send()`) : un courriel mis en file
     * termine son job sans échec. Aucun courriel ne part donc plus à une telle
     * adresse, qu'un compte d'avant la règle peut porter : ni le lien « mot de
     * passe oublié », ni l'avis d'une tentative sur l'adresse
     * (`TentativeSurTonAdresse`), ni aucun autre. Il échouait sinon, erreur
     * masquée (`CourrielleurQuiTaitLesAdresses`), ou partait à une autre
     * adresse quand la messagerie en lisait une entre les chevrons d'une
     * partie locale entre guillemets. Toute autre adresse se rend seule, sans
     * nom, comme Laravel la rendait (`$this->email`). Une notification à la
     * demande (`Notification::route()`) ne passe pas par ici : chaque envoi
     * de ce genre juge lui-même son adresse (`SurveilleSonAdresse`) ou ne part
     * qu'à une adresse que la validation a jugée.
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->laMessagerieSaitEcrireSonAdresse() ? $this->email : null;
    }

    /**
     * Le fournisseur de connexion relié au compte, nommé pour l'écran, ou null.
     *
     * Un compte ouvert par un fournisseur a reçu un mot de passe aléatoire que
     * personne ne connaît (`ResolveSocialUserAction`) ; un compte à mot de
     * passe relié ensuite à un fournisseur garde le sien. Les deux portent
     * `provider`, et rien en base ne les distingue : le profil s'adresse donc
     * aux deux quand il explique comment obtenir un mot de passe.
     */
    public function fournisseurDeConnexion(): ?string
    {
        return match ($this->provider) {
            null, '' => null,
            'github' => 'GitHub',
            'google' => 'Google',
            'apple' => 'Apple',
            default => ucfirst($this->provider),
        };
    }

    /**
     * @return HasOne<WarmupPreference, $this>
     */
    public function warmupPreference(): HasOne
    {
        return $this->hasOne(WarmupPreference::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'avatar', 'default_rest_time'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'en_attente_depuis' => 'datetime',
            'liaison_prouvee_le' => 'datetime',
            'password' => 'hashed',
            'default_rest_time' => 'integer',
            'auto_rest_timer' => 'boolean',
            'current_streak' => 'integer',
            'longest_streak' => 'integer',
            'last_workout_at' => 'datetime',
        ];
    }

    /**
     * Tout ce que l'utilisateur a souleve : la somme de ses seances, lue au
     * moment ou on la demande, sans compteur a tenir a chaque serie.
     */
    public function volumeSouleve(): float
    {
        return (float) $this->workouts()->sum('workout_volume');
    }
}
