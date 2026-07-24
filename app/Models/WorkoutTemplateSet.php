<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WorkoutTemplateSetFactory;
/**
 * @property int $id
 * @property int $workout_template_line_id
 * @property int|null $reps
 * @property float|null $weight
 * @property bool $is_warmup
 * @property int $order
 * @property-read WorkoutTemplateLine $workoutTemplateLine
 */
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkoutTemplateSet extends Model
{
    /** @use HasFactory<WorkoutTemplateSetFactory> */
    use HasFactory;

    protected $fillable = [
        'workout_template_line_id',
        'reps',
        'weight',
        'is_warmup',
        'order',
    ];

    /**
     * @return BelongsTo<WorkoutTemplateLine, $this>
     */
    public function workoutTemplateLine(): BelongsTo
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
