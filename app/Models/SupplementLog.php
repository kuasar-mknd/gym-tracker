<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplementLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplement_id',
        'user_id',
        'quantity',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'consumed_at' => 'datetime',
        ];
    }

    public function supplement(): BelongsTo
    {
        return $this->belongsTo(Supplement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
