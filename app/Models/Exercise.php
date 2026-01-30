<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property string $type
 * @property string $category
 * @property int|null $default_rest_time
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\WorkoutLine> $workoutLines
 * @property-read \App\Models\User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder|Exercise forUser(int $userId)
 */
class Exercise extends Model
{
    /** @use HasFactory<\Database\Factories\ExerciseFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = ['name', 'type', 'category', 'default_rest_time'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\WorkoutLine, $this>
     */
    public function workoutLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WorkoutLine::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope the query to include system exercises and exercises owned by the given user.
     */
    /**
     * @param  \Illuminate\Database\Eloquent\Builder<$this>  $query
     * @return \Illuminate\Database\Eloquent\Builder<$this>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $userId));
    }

    /**
     * Get exercises for the user, cached for 1 hour.
     *
     * @return Collection<int, Exercise>
     */
    public static function getCachedForUser(int $userId): Collection
    {
        return Cache::remember(
            "exercises_list_{$userId}",
            3600,
            fn () => self::forUser($userId)->orderBy('name')->get()
        );
    }

    /**
     * Clear the exercises cache for the user.
     */
    public static function clearCacheForUser(int $userId): void
    {
        Cache::forget("exercises_list_{$userId}");
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'type', 'category', 'default_rest_time'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * The "booted" method of the model.
     *
     * Handles automatic cache invalidation for the 'exercises_list_{userId}' key.
     */
    protected static function booted(): void
    {
        static::saved(function (Exercise $exercise): void {
            if ($exercise->user_id) {
                Cache::forget('exercises_list_'.$exercise->user_id);
            }
        });

        static::deleted(function (Exercise $exercise): void {
            if ($exercise->user_id) {
                Cache::forget('exercises_list_'.$exercise->user_id);
            }
        });
    }
}
