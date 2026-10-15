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
     */
    #[\Override]
    public function sendEmailVerificationNotification(): void
    {
        if ($this->estEnAttente()) {
            $this->notify(new LienDOuvertureDuCompte());

            return;
        }

        parent::sendEmailVerificationNotification();
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
