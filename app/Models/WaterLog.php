<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class WaterLog extends Model
{
    /** @use HasFactory<\Database\Factories\WaterLogFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'amount',
        'consumed_at',
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
            'amount' => 'integer',
            'consumed_at' => 'datetime',
        ];
    }

    public function scopeConsumedAtBetween(Builder $query, $dates): Builder
    {
        $dates = is_array($dates) ? $dates : explode(',', $dates);

        return $query->whereBetween('consumed_at', [
            Carbon::parse($dates[0])->startOfDay(),
            Carbon::parse($dates[1] ?? $dates[0])->endOfDay(),
        ]);
    }
}
