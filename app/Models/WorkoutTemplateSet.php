<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $workout_template_line_id
 * @property int|null $reps
 * @property float|null $weight
 * @property bool $is_warmup
 * @property int $order
 * @property-read \App\Models\WorkoutTemplateLine $workoutTemplateLine
 */
class WorkoutTemplateSet extends Model
{
    protected $fillable = [
        'workout_template_line_id',
        'reps',
        'weight',
        'is_warmup',
        'order',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\WorkoutTemplateLine, $this>
     */
    public function workoutTemplateLine(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WorkoutTemplateLine::class);
    }

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'is_warmup' => 'boolean',
        ];
    }
}
