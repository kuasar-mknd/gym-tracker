<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplementLog extends Model
{
    /** @use HasFactory<\Database\Factories\SupplementLogFactory> */
    use HasFactory;

    protected $fillable = [
        'supplement_id',
        'user_id',
        'quantity',
        'consumed_at',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Supplement, $this>
     */
    public function supplement(): BelongsTo
    {
        return $this->belongsTo(Supplement::class);
    }

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
            'quantity' => 'integer',
            'consumed_at' => 'datetime',
        ];
    }
}
