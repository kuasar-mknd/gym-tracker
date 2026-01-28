<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $start_time
 * @property \Illuminate\Support\Carbon|null $end_time
 * @property int $target_duration_hours
 * @property string $method
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 */
class FastingLog extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'user_id',
        'start_time',
        'end_time',
        'target_duration_hours',
        'method',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'target_duration_hours' => 'integer',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['start_time', 'end_time', 'target_duration_hours', 'method'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Get the duration in hours.
     */
    public function getDurationHoursAttribute(): float
    {
        $end = $this->end_time ?? now();
        return $this->start_time->diffInMinutes($end) / 60;
    }

    /**
     * Get the progress percentage (0-100).
     */
    public function getProgressPercentageAttribute(): float
    {
        $duration = $this->getDurationHoursAttribute();
        if ($this->target_duration_hours <= 0) return 100;
        return min(100, ($duration / $this->target_duration_hours) * 100);
    }
}
