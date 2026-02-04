<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IntervalTimerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntervalTimer extends Model
{
    /** @use HasFactory<IntervalTimerFactory> */
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'work_seconds',
        'rest_seconds',
        'rounds',
        'warmup_seconds',
    ];

    /**
     * @return BelongsTo<\App\Models\User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_seconds' => 'integer',
            'rest_seconds' => 'integer',
            'rounds' => 'integer',
            'warmup_seconds' => 'integer',
        ];
    }
}
