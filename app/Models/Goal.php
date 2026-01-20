<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function getProgressAttribute(): float
    {
        if ($this->target_value == $this->start_value) {
            return $this->current_value >= $this->target_value ? 100 : 0;
        }

        $totalDiff = abs($this->target_value - $this->start_value);
        $currentDiff = abs($this->current_value - $this->start_value);

        if ($totalDiff == 0) {
            return 0;
        }

        $progress = ($currentDiff / $totalDiff) * 100;

        return min(max($progress, 0), 100);
    }

    public function getUnitAttribute(): string
    {
        switch ($this->type) {
            case 'weight':
            case 'volume':
                return 'kg';
            case 'frequency':
                return 'séances';
            case 'measurement':
                return $this->measurement_type === 'body_fat' ? '%' : 'cm';
            default:
                return '';
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'type', 'target_value', 'current_value', 'deadline', 'completed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
