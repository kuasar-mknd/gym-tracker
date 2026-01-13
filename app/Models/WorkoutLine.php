<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkoutLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'workout_id',
        'exercise_id',
        'order',
    ];

    public function workout(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Workout::class);
    }

    public function exercise(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function sets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Set::class);
    }
}
