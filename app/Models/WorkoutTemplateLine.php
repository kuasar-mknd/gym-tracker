<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WorkoutTemplateLineFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workout_template_id
 * @property int $exercise_id
 * @property int $order
 * @property-read WorkoutTemplate $workoutTemplate
 * @property-read Exercise $exercise
 * @property-read Collection<int, WorkoutTemplateSet> $workoutTemplateSets
 */
class WorkoutTemplateLine extends Model
{
    /** @use HasFactory<WorkoutTemplateLineFactory> */
    use HasFactory;

    protected $fillable = [
        'workout_template_id',
        'exercise_id',
        'order',
    ];

    /**
     * @return BelongsTo<WorkoutTemplate, $this>
     */
    public function workoutTemplate(): BelongsTo
    {
        return $this->belongsTo(WorkoutTemplate::class);
    }

    /**
     * @return BelongsTo<Exercise, $this>
     */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /**
     * @return HasMany<WorkoutTemplateSet, $this>
     */
    public function workoutTemplateSets(): HasMany
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
