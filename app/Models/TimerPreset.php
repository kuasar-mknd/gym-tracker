<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimerPreset extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'work_seconds',
        'rest_seconds',
        'rounds',
        'warmup_seconds',
        'cooldown_seconds',
    ];

    protected $casts = [
        'work_seconds' => 'integer',
        'rest_seconds' => 'integer',
        'rounds' => 'integer',
        'warmup_seconds' => 'integer',
        'cooldown_seconds' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
