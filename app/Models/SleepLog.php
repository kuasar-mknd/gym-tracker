<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $date
 * @property int $duration_minutes
 * @property int $quality
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 */
class SleepLog extends Model
{
    /** @use HasFactory<\Database\Factories\SleepLogFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'duration_minutes',
        'quality',
        'notes',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'duration_minutes' => 'integer',
            'quality' => 'integer',
        ];
    }
}
