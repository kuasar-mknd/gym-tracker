<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Injury extends Model
{
    /** @use HasFactory<\Database\Factories\InjuryFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'body_part',
        'description',
        'status',
        'injured_at',
        'healed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'injured_at' => 'date',
            'healed_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
