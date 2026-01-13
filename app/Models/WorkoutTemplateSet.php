<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkoutTemplateSet extends Model
{
    protected $fillable = [
        'workout_template_line_id',
        'reps',
        'weight',
        'is_warmup',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'is_warmup' => 'boolean',
        ];
    }

    public function workoutTemplateLine(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WorkoutTemplateLine::class);
    }
}
