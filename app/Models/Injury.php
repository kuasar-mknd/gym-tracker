<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class Injury extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'body_part',
        'diagnosis',
        'severity',
        'status',
        'pain_level',
        'occurred_at',
        'healed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'date',
            'healed_at' => 'date',
            'pain_level' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', ['active', 'recovering']);
    }
}
