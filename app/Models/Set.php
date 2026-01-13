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
    ];

    public function workoutLine(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WorkoutLine::class);
    }
}
