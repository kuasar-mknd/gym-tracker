<?php

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
 * @property-read \App\Models\WorkoutLine $workoutLine
 * @property-read \App\Models\PersonalRecord|null $personalRecord
 */
class Set extends Model
{
    /** @use HasFactory<\Database\Factories\SetFactory> */
    use HasFactory;

    protected $fillable = [
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
