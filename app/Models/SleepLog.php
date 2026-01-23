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
        'started_at',
        'ended_at',
        'quality',
        'notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'quality' => 'integer',
    ];

    /**
     * @return BelongsTo<User, SleepLog>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
