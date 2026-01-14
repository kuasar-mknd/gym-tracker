<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Plate extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'weight',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'quantity' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
