<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $body_part
 * @property string $description
 * @property int $severity
 * @property string $status
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property \Illuminate\Support\Carbon|null $recovered_at
 * @property string|null $notes
 * @property-read \App\Models\User $user
 */
class Injury extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'body_part',
        'description',
        'severity',
        'status',
        'occurred_at',
        'recovered_at',
        'notes',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'severity' => 'integer',
            'occurred_at' => 'date:Y-m-d',
            'recovered_at' => 'date:Y-m-d',
        ];
    }
}
