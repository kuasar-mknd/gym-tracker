<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkoutLine extends Model
{
    protected $fillable = [
        'workout_id',
        'exercise_id',
        'order',
    ];

    public function workout()
    {
        return $this->belongsTo(Workout::class);
    }

    public function exercise()
    {
        return $this->belongsTo(Exercise::class);
    }

    public function sets()
    {
        return $this->hasMany(Set::class);
    }
}
