<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Injury extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'body_part',
        'description',
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

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', ['active', 'recovering']);
    }

    public function scopeHealed(Builder $query): void
    {
        $query->where('status', 'healed');
    }
}
