<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $workout_line_id
 * @property float|null $weight
 * @property int|null $reps
 * @property int|null $duration_seconds
 * @property float|null $distance_km
 * @property bool $is_warmup
 * @property bool $is_completed
 * @property string|null $idempotency_key names the client attempt that created this row, so a
 *                                        replayed create returns it instead of making a second one. Deliberately absent from
 *                                        $fillable: it identifies the attempt, never something a payload may set.
 * @property-read \App\Models\WorkoutLine $workoutLine
 * @property-read \App\Models\PersonalRecord|null $personalRecord
 */
class Set extends Model
{
    /** @use HasFactory<\Database\Factories\SetFactory> */
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'workout_line_id',
        'weight',
        'reps',
        'duration_seconds',
        'distance_km',
        'is_warmup',
        'is_completed',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\WorkoutLine, $this>
     */
    public function workoutLine(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WorkoutLine::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<\App\Models\PersonalRecord, $this>
     */
    public function personalRecord(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PersonalRecord::class);
    }

    /**
     * Get the volume of the set (weight * reps).
     */
    public function getVolume(): float
    {
        return (float) ($this->weight ?? 0) * (int) ($this->reps ?? 0);
    }

    /**
     * Get the original volume of the set before changes.
     */
    public function getOriginalVolume(): float
    {
        $weight = $this->getOriginal('weight');
        $reps = $this->getOriginal('reps');

        return (is_numeric($weight) ? (float) $weight : 0.0) * (is_numeric($reps) ? (int) $reps : 0);
    }

    /**
     * Update the total volume for the user and the workout.
     */
    public function updateVolumes(): void
    {
        $this->syncVolumes();
    }

    /**
     * Decrement the total volume for the user and the workout.
     */
    public function decrementVolumes(): void
    {
        $this->syncVolumes();
    }

    /**
     * Brings both counters back in line with the sets that exist.
     *
     * These used to accumulate a delta per save — the set's new volume minus the
     * volume it had when the model was loaded. Two requests touching the same
     * row at once, which is exactly what the per-field debounce produces when a
     * user corrects a weight and a rep count together, each computed their delta
     * from the same snapshot. Both were applied, and the totals drifted away
     * from the sets they claim to describe, permanently and with nothing to show
     * for it.
     */
    private function syncVolumes(): void
    {
        $this->loadMissing('workoutLine.workout.user');

        $this->workoutLine?->workout?->recomputeVolume();
    }

    #[\Override]
    protected static function booted(): void
    {
        static::saved(function (Set $set): void {
            $set->updateVolumes();
        });

        static::deleted(function (Set $set): void {
            $set->decrementVolumes();
        });
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'is_warmup' => 'boolean',
            'is_completed' => 'boolean',
            'weight' => 'float',
            'distance_km' => 'float',
            'duration_seconds' => 'integer',
        ];
    }
}
