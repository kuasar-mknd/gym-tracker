<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $start_time
 * @property \Illuminate\Support\Carbon|null $end_time
 * @property int $target_duration_minutes
 * @property string $type
 * @property string $status
 * @property-read \App\Models\User $user
 * @property-read int $duration_minutes
 * @property-read int $progress_percent
 */
class Fast extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'start_time',
        'end_time',
        'target_duration_minutes',
        'type',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'target_duration_minutes' => 'integer',
        ];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getDurationMinutesAttribute(): int
    {
        $end = $this->end_time ?? now();
        return (int) $this->start_time->diffInMinutes($end);
    }

    public function getProgressPercentAttribute(): int
    {
        if ($this->target_duration_minutes <= 0) {
            return 100;
        }

        $duration = $this->duration_minutes;

        return min(100, (int) round(($duration / $this->target_duration_minutes) * 100));
    }
}
