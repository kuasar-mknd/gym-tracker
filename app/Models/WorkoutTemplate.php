<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkoutTemplate extends Model
{
    protected $fillable = [
        'name',
        'description',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workoutTemplateLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WorkoutTemplateLine::class);
    }
}
