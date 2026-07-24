<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GoalType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property GoalType $type
 * @property float $target_value
 * @property float $current_value
 * @property float $start_value
 * @property int|null $exercise_id
 * @property string|null $measurement_type
 * @property \Illuminate\Support\Carbon|null $deadline
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property float $progress_pct
 * @property-read string $unit
 * @property-read \App\Models\User $user
 * @property-read \App\Models\Exercise|null $exercise
 */
class Goal extends Model
{
    /** @use HasFactory<\Database\Factories\GoalFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'title',
        'type',
        'target_value',
        'current_value',
        'start_value',
        'progress_pct',
        'exercise_id',
        'measurement_type',
        'deadline',
        'completed_at',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Exercise, $this>
     */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function getUnitAttribute(): string
    {
        return match ($this->type) {
            GoalType::Weight, GoalType::Volume => 'kg',
            GoalType::Frequency => 'séances',
            GoalType::Measurement => $this->measurement_type === 'body_fat' ? '%' : 'cm',
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'type', 'target_value', 'current_value', 'deadline', 'completed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'type' => GoalType::class,
            'target_value' => 'double',
            'current_value' => 'double',
            'start_value' => 'double',
            'deadline' => 'date',
            'completed_at' => 'datetime',
        ];
    }
}
