<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SupplementLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $supplement_id
 * @property int $user_id
 * @property int $quantity
 * @property Carbon $consumed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Supplement $supplement
 * @property-read User $user
 */
class SupplementLog extends Model
{
    /** @use HasFactory<SupplementLogFactory> */
    use HasFactory;

    protected $fillable = [
        'supplement_id',
        'user_id',
        'quantity',
        'consumed_at',
    ];

    /**
     * @return BelongsTo<Supplement, $this>
     */
    public function supplement(): BelongsTo
    {
        return $this->belongsTo(Supplement::class);
    }

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
            'quantity' => 'integer',
            'consumed_at' => 'datetime',
        ];
    }
}
