<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\RecommendedValuesService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;

/**
 * @property int $id
 * @property int $workout_id
 * @property int $exercise_id
 * @property int $order
 * @property string|null $notes
 * @property string|null $idempotency_key names the client attempt that created this row, so a
 *                                        replayed create returns it instead of making a second one. Deliberately absent from
 *                                        $fillable: it identifies the attempt, never something a payload may set.
 * @property-read \App\Models\Workout $workout
 * @property-read \App\Models\Exercise $exercise
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Set> $sets
 */
class WorkoutLine extends Model
{
    /** @use HasFactory<\Database\Factories\WorkoutLineFactory> */
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'exercise_id',
        'order',
        'notes',
    ];

    /**
     * @var list<string>
     */
    #[\Override]
    protected $appends = [];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Workout, $this>
     */
    public function workout(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Workout::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Exercise, $this>
     */
    public function exercise(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Set, $this>
     */
    public function sets(): \Illuminate\Database\Eloquent\Relations\HasMany
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
     * @param  \Illuminate\Database\Eloquent\Collection<int, WorkoutLine>  $lines
     * @return array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}>
     */
    public static function batchRecommendedValues(\Illuminate\Database\Eloquent\Collection $lines, int $userId): array
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

    #[\Override]
    protected static function booted(): void
    {
        $clearCache = function (self $line): void {
            if ($line->workout_id) {
                \Illuminate\Support\Facades\Cache::forget("user_active_workout_{$line->workout->user_id}");
            }
        };

        static::saved($clearCache);
        static::deleted($clearCache);

        /**
         * Gives back the volume of the sets this line is about to take with it.
         *
         * sets.workout_line_id is ON DELETE CASCADE, so the database removes
         * those rows itself and Eloquent never hears about it — Set::deleted
         * does not fire, and the volume they contributed stays in
         * users.total_volume and workouts.workout_volume for good. Every
         * exercise ever removed from a session has been inflating those two
         * counters since.
         *
         * Summed in one query and released once, rather than deleting each set
         * through the model: the rows are going regardless, and the counters
         * only care about the total.
         */
        static::deleting(function (self $line): void {
            $volume = (float) $line->sets()->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(weight, 0) * COALESCE(reps, 0)'));

            if ($volume === 0.0) {
                return;
            }

            $workout = $line->workout;

            $workout?->decrement('workout_volume', $volume);
            $workout?->user?->decrement('total_volume', $volume);
        });
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }
}
