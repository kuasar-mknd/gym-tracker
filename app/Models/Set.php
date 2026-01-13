<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Set extends Model
{
    protected $fillable = [
        'workout_line_id',
        'weight',
        'reps',
        'duration_seconds',
        'distance_km',
        'is_warmup',
    ];

    public function workoutLine()
    {
        return $this->belongsTo(WorkoutLine::class);
    }
}
