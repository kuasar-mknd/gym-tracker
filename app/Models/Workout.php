<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WorkoutFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $name
 * @property float $workout_volume
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property string|null $notes
 * @property-read User $user
 * @property-read Collection<int, WorkoutLine> $workoutLines
 */
class Workout extends Model
{
    /** @use HasFactory<WorkoutFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'name',
        'started_at',
        'ended_at',
        'notes',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<WorkoutLine, $this>
     */
    public function workoutLines(): HasMany
    {
        return $this->hasMany(WorkoutLine::class)->orderBy('order');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['started_at', 'ended_at', 'name'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'workout_volume' => 'float',
        ];
    }

    protected static function booted(): void
    {
        $clearCache = function (self $workout): void {
            Cache::forget("user_active_workout_{$workout->user_id}");
        };

        static::saved($clearCache);
        static::deleted($clearCache);
    }
}
