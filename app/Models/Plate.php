<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $user_id
 */
class Plate extends Model
{
    /** @use HasFactory<PlateFactory> */
    use HasFactory;

    protected $fillable = [
        'weight',
        'quantity',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'quantity' => 'integer',
        ];
    }
}
