<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkoutTemplateLine extends Model
{
    protected $fillable = [
        'workout_template_id',
        'exercise_id',
        'order',
    ];

    public function workoutTemplate(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WorkoutTemplate::class);
    }

    public function exercise(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function workoutTemplateSets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WorkoutTemplateSet::class);
    }
}
