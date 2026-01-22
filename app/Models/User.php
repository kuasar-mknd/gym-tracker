<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
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
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Workout> $workouts
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $unreadNotifications
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasPushSubscriptions, LogsActivity, Notifiable;

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
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Workout, $this>
     */
    public function workouts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Workout::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\BodyMeasurement, $this>
     */
    public function bodyMeasurements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BodyMeasurement::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\BodyPartMeasurement, $this>
     */
    public function bodyPartMeasurements(): HasMany
    {
        return $this->hasMany(BodyPartMeasurement::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\PersonalRecord, $this>
     */
    public function personalRecords(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PersonalRecord::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\DailyJournal, $this>
     */
    public function dailyJournals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DailyJournal::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Plate, $this>
     */
    public function plates(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Plate::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\WilksScore, $this>
     */
    public function wilksScores(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WilksScore::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\NotificationPreference, $this>
     */
    public function notificationPreferences(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Goal, $this>
     */
    public function goals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Goal::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\Achievement, $this>
     */
    public function achievements(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
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
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\WorkoutTemplate, $this>
     */
    public function workoutTemplates(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WorkoutTemplate::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\MacroCalculation, $this>
     */
    public function macroCalculations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MacroCalculation::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Habit, $this>
     */
    public function habits(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Habit::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\WaterLog, $this>
     */
    public function waterLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WaterLog::class);
    }

    public function isPushEnabled(string $type): bool
    {
        return (bool) $this->notificationPreferences()
            ->where('type', $type)
            ->where('is_push_enabled', true)
            ->exists();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<\App\Models\WarmupPreference, $this>
     */
    public function warmupPreference(): \Illuminate\Database\Eloquent\Relations\HasOne
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

    public function getUnreadNotificationsCountCached(): int
    {
        return Cache::remember(
            "user:{$this->id}:unread_notifications_count",
            now()->addSeconds(30),
            fn () => $this->unreadNotifications()->count()
        );
    }

    public function getLatestAchievementCached()
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
}
