<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string $type
 * @property float $target_value
 * @property float $current_value
 * @property float $start_value
 * @property int|null $exercise_id
 * @property string|null $measurement_type
 * @property \Illuminate\Support\Carbon|null $deadline
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property-read float $progress
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

    public function getProgressAttribute(): float
    {
        if ($this->target_value === $this->start_value) {
            return $this->current_value >= $this->target_value ? 100 : 0;
        }

        $totalDiff = abs($this->target_value - $this->start_value);
        $currentDiff = abs($this->current_value - $this->start_value);

        if ($totalDiff === 0.0) {
            return 0;
        }

        $progress = $currentDiff / $totalDiff * 100;

        return min(max($progress, 0), 100);
    }

    public function getUnitAttribute(): string
    {
        return match ($this->type) {
            'weight', 'volume' => 'kg',
            'frequency' => 'séances',
            'measurement' => $this->measurement_type === 'body_fat' ? '%' : 'cm',
            default => '',
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
            'target_value' => 'double',
            'current_value' => 'double',
            'start_value' => 'double',
            'deadline' => 'date',
            'completed_at' => 'datetime',
        ];
    }
}
