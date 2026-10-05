<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\EffaceSesTracesPolymorphes;
use App\Models\Traits\HasFitnessData;
use App\Models\Traits\HasToolsData;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
    use EffaceSesTracesPolymorphes;
    use HasApiTokens;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    use HasFitnessData;
    use HasPushSubscriptions;
    use HasToolsData;
    use LogsActivity;
    use Notifiable;

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

    /**
     * Un mot de passe changé détache du compte tous ses appareils abonnés aux
     * notifications push.
     *
     * Depuis #1940, changer le mot de passe ferme les autres sessions du compte
     * (`AuthentifieLaSessionDuCompte`). Leurs appareils gardaient pourtant leur
     * abonnement, et continuaient d'afficher records, rappels et succès sur
     * l'écran verrouillé : précisément l'appareil volé, partagé ou perdu dont
     * la personne voulait se défaire. Le serveur ne sait pas quel abonnement
     * appartient à quelle session, la table ne porte ni l'une ni l'autre : tous
     * partent. La page du profil retransmet ensuite celui de l'appareil qui a
     * changé le mot de passe. Un appareil retiré qui se reconnecte au compte
     * retransmet le sien à son tour (`useAbonnementPush`).
     *
     * Une session fermée ne peut plus en transmettre : le middleware la refuse
     * à l'entrée de la requête, et `PushSubscriptionController` la revérifie
     * avant d'écrire, sous le verrou de la ligne du compte, que la mise à jour
     * du mot de passe prend aussi. Sans cette revérification, une
     * transmission partie avant le changement s'écrivait après ce retrait, et
     * la session fermée gardait son abonnement.
     *
     * Dans l'évènement du modèle plutôt que dans chaque contrôleur, pour la
     * même raison que le middleware ferme les sessions : le profil, la
     * réinitialisation par courriel, le panneau et tout chemin à venir
     * changent le mot de passe par un enregistrement du compte, et une règle
     * que chacun devrait rappeler finit oubliée par l'un d'eux. Seuls les
     * chemins qui sautent les évènements y échappent (`saveQuietly()`,
     * `withoutEvents()`, le constructeur de requêtes) ; l'application n'en
     * écrit aucun sur le mot de passe.
     *
     * Le re-hachage à la connexion, quand le coût du hachage change
     * (`hashing.rehash_on_login`), compte aussi comme un changement : il ferme
     * déjà les autres sessions du compte, et chaque appareil retransmet son
     * abonnement à sa connexion suivante.
     */
    #[\Override]
    protected static function booted(): void
    {
        self::updated(static function (self $compte): void {
            if ($compte->wasChanged('password')) {
                $compte->detacherSesAppareilsPush();
            }
        });
    }

    /**
     * Retire tous les abonnements push du compte, par sa relation, qui filtre
     * sur le type et l'identifiant. La relation déjà chargée est oubliée : le
     * canal WebPush lit les adresses par elle, et une notification envoyée par
     * cette instance dans la même requête partirait sinon vers les appareils
     * retirés.
     */
    private function detacherSesAppareilsPush(): void
    {
        $this->pushSubscriptions()->delete();
        $this->unsetRelation('pushSubscriptions');
    }
}
