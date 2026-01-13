<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Set extends Model
{
    use HasFactory;

    protected $fillable = [
        'workout_line_id',
        'weight',
        'reps',
        'duration_seconds',
        'distance_km',
        'is_warmup',
        'is_completed',
    ];

    protected function casts(): array
    {
        return [
            'is_warmup' => 'boolean',
            'is_completed' => 'boolean',
        ];
    }

    public function workoutLine(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WorkoutLine::class);
    }

    public function personalRecord(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PersonalRecord::class);
    }
}
