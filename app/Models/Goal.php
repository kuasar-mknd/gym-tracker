<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GoalType;
use Database\Factories\GoalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
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
 * @property Carbon|null $deadline
 * @property Carbon|null $completed_at
 * @property float $progress_pct
 * @property-read string $unit
 * @property-read User $user
 * @property-read Exercise|null $exercise
 */
class Goal extends Model
{
    /** @use HasFactory<GoalFactory> */
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Exercise, $this>
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
