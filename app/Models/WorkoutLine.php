<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\RecommendedValuesService;
use Database\Factories\WorkoutLineFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;

/**
 * @property int $id
 * @property int $workout_id
 * @property int $exercise_id
 * @property int $order
 * @property string|null $notes
 * @property-read Workout $workout
 * @property-read Exercise $exercise
 * @property-read Collection<int, Set> $sets
 */
class WorkoutLine extends Model
{
    /** @use HasFactory<WorkoutLineFactory> */
    use HasFactory;

    protected $fillable = [
        'exercise_id',
        'order',
        'notes',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [];

    /**
     * @return BelongsTo<Workout, $this>
     */
    public function workout(): BelongsTo
    {
        return $this->belongsTo(Workout::class);
    }

    /**
     * @return BelongsTo<Exercise, $this>
     */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /**
     * @return HasMany<Set, $this>
     */
    public function sets(): HasMany
    {
        return $this->hasMany(Set::class);
    }

    /**
     * @return array{weight: float, reps: int, distance_km: float, duration_seconds: int}
     */
    public function getRecommendedValuesAttribute(): array
    {
        if (! isset($this->attributes['recommended_values'])) {
            return app(RecommendedValuesService::class)->getRecommendedValues($this);
        }

        /** @var string|null $val */
        $val = $this->attributes['recommended_values'];
        /** @var array{weight: float, reps: int, distance_km: float, duration_seconds: int} $decoded */
        $decoded = json_decode((string) $val, true);

        return is_array($decoded) ? $decoded : ['weight' => 0.0, 'reps' => 0, 'distance_km' => 0.0, 'duration_seconds' => 0];
    }

    /**
     * @param  array{weight: float, reps: int, distance_km: float, duration_seconds: int}  $values
     */
    public function setRecommendedValuesAttribute(array $values): void
    {
        $this->attributes['recommended_values'] = json_encode($values);
    }

    /**
     * @param  Collection<int, WorkoutLine>  $lines
     * @return array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}>
     */
    public static function batchRecommendedValues(Collection $lines, int $userId): array
    {
        return app(RecommendedValuesService::class)->batchRecommendedValues($lines, $userId);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['exercise.name', 'order', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        $clearCache = function (self $line): void {
            if ($line->workout_id) {
                Cache::forget("user_active_workout_{$line->workout->user_id}");
            }
        };

        static::saved($clearCache);
        static::deleted($clearCache);
    }

    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }
}
