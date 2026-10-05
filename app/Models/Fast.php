<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fast extends Model
{
    /** @use HasFactory<\Database\Factories\FastFactory> */
    use HasFactory;

    /**
     * La durée cible la plus longue d'un jeûne, en minutes : une semaine. Le
     * plus long type proposé (48:0) en fait 2 880.
     */
    public const int DUREE_CIBLE_MAX_MINUTES = 10_080;

    #[\Override]
    protected $fillable = [
        'user_id',
        'start_time',
        'end_time',
        'target_duration_minutes',
        'type',
        'status',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'target_duration_minutes' => 'integer',
        ];
    }
}
