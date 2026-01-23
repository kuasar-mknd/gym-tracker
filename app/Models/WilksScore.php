<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WilksScore extends Model
{
    protected $fillable = [
        'user_id',
        'body_weight',
        'lifted_weight',
        'gender',
        'unit',
        'score',
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
            'body_weight' => 'decimal:2',
            'lifted_weight' => 'decimal:2',
            'score' => 'decimal:2',
        ];
    }
}
