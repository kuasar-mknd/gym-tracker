<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $start_time
 * @property \Illuminate\Support\Carbon|null $end_time
 * @property float $target_duration_hours
 * @property string $type
 * @property string $status
 * @property string|null $note
 * @property-read \App\Models\User $user
 */
class FastingLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'start_time',
        'end_time',
        'target_duration_hours',
        'type',
        'status',
        'note',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'target_duration_hours' => 'float',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the duration of the fast in hours.
     */
    public function getDurationHoursAttribute(): float
    {
        $end = $this->end_time ?? now();
        return $this->start_time->diffInMinutes($end) / 60;
    }

    /**
     * Get the progress percentage (0-100).
     */
    public function getProgressAttribute(): float
    {
        if ($this->target_duration_hours <= 0) {
            return 100;
        }

        $duration = $this->getDurationHoursAttribute();
        return min(100, ($duration / $this->target_duration_hours) * 100);
    }
}
