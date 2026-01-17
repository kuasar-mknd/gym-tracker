<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WilksScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'body_weight',
        'lifted_weight',
        'gender',
        'unit',
        'score',
    ];

    protected $casts = [
        'body_weight' => 'decimal:2',
        'lifted_weight' => 'decimal:2',
        'score' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
