<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SleepLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'duration_minutes',
        'quality',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'duration_minutes' => 'integer',
        'quality' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
