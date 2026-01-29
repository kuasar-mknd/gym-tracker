<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Injury extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'body_part',
        'status',
        'pain_level',
        'occurred_at',
        'healed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'date:Y-m-d',
            'healed_at' => 'date:Y-m-d',
            'pain_level' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
