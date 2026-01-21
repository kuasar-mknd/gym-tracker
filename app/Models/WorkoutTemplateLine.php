<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $workout_template_id
 * @property int $exercise_id
 * @property int $order
 * @property-read \App\Models\WorkoutTemplate $workoutTemplate
 * @property-read \App\Models\Exercise $exercise
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\WorkoutTemplateSet> $workoutTemplateSets
 */
class WorkoutTemplateLine extends Model
{
    protected $fillable = [
        'workout_template_id',
        'exercise_id',
        'order',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\WorkoutTemplate, $this>
     */
    public function workoutTemplate(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WorkoutTemplate::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Exercise, $this>
     */
    public function exercise(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\WorkoutTemplateSet, $this>
     */
    public function workoutTemplateSets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WorkoutTemplateSet::class);
    }

    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }
}
