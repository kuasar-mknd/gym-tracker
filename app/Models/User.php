<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification as Notification;
use Illuminate\Notifications\DatabaseNotificationCollection as NotifColl;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

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
    use HasApiTokens;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    use HasPushSubscriptions;
    use LogsActivity;
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'provider',
        'provider_id',
        'avatar',
        'default_rest_time',
        'current_streak',
        'longest_streak',
        'last_workout_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return HasMany<Workout, $this>
     */
    public function workouts(): HasMany
    {
        return $this->hasMany(Workout::class);
    }

    /**
     * @return HasMany<BodyMeasurement, $this>
     */
    public function bodyMeasurements(): HasMany
    {
        return $this->hasMany(BodyMeasurement::class);
    }

    /**
     * @return HasMany<BodyPartMeasurement, $this>
     */
    public function bodyPartMeasurements(): HasMany
    {
        return $this->hasMany(BodyPartMeasurement::class);
    }

    /**
     * @return HasMany<PersonalRecord, $this>
     */
    public function personalRecords(): HasMany
    {
        return $this->hasMany(PersonalRecord::class);
    }

    /**
     * @return HasMany<DailyJournal, $this>
     */
    public function dailyJournals(): HasMany
    {
        return $this->hasMany(DailyJournal::class);
    }

    /**
     * @return HasMany<Plate, $this>
     */
    public function plates(): HasMany
    {
        return $this->hasMany(Plate::class);
    }

    /**
     * @return HasMany<WilksScore, $this>
     */
    public function wilksScores(): HasMany
    {
        return $this->hasMany(WilksScore::class);
    }

    /**
     * @return HasMany<NotificationPreference, $this>
     */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /**
     * @return HasMany<Goal, $this>
     */
    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    /**
     * @return BelongsToMany<Achievement, $this, \Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>
     */
    public function achievements(): BelongsToMany
    {
        return $this->belongsToMany(Achievement::class, 'user_achievements')
            ->withPivot('achieved_at')
            ->withTimestamps();
    }

    public function isNotificationEnabled(string $type): bool
    {
        return (bool) $this->notificationPreferences()
            ->where('type', $type)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * @return HasMany<WorkoutTemplate, $this>
     */
    public function workoutTemplates(): HasMany
    {
        return $this->hasMany(WorkoutTemplate::class);
    }

    /**
     * @return HasMany<MacroCalculation, $this>
     */
    public function macroCalculations(): HasMany
    {
        return $this->hasMany(MacroCalculation::class);
    }

    /**
     * @return HasMany<Habit, $this>
     */
    public function habits(): HasMany
    {
        return $this->hasMany(Habit::class);
    }

    /**
     * @return HasMany<WaterLog, $this>
     */
    public function waterLogs(): HasMany
    {
        return $this->hasMany(WaterLog::class);
    }

    /**
     * @return HasMany<IntervalTimer, $this>
     */
    public function intervalTimers(): HasMany
    {
        return $this->hasMany(IntervalTimer::class);
    }

    /**
     * @return HasMany<Fast, $this>
     */
    public function fasts(): HasMany
    {
        return $this->hasMany(Fast::class);
    }

    /**
     * @return HasMany<SupplementLog, $this>
     */
    public function supplementLogs(): HasMany
    {
        return $this->hasMany(SupplementLog::class);
    }

    public function isPushEnabled(string $type): bool
    {
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
            ->dontSubmitEmptyLogs();
    }

    public function getUnreadNotificationsCountCached(): int
    {
        return (int) Cache::remember(
            "user:{$this->id}:unread_notifications_count",
            now()->addSeconds(30),
            fn () => $this->unreadNotifications()->count()
        );
    }

    public function getLatestAchievementCached(): ?Notification
    {
        return Cache::remember(
            "user:{$this->id}:latest_achievement",
            now()->addSeconds(30),
            fn () => $this->unreadNotifications()
                ->where('type', \App\Notifications\AchievementUnlocked::class)
                ->latest()
                ->first()
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'default_rest_time' => 'integer',
            'current_streak' => 'integer',
            'longest_streak' => 'integer',
            'last_workout_at' => 'datetime',
        ];
    }
}
