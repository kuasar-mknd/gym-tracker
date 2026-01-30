<?php

declare(strict_types=1);

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

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\WaterLog>  $query
     * @param  array<mixed>|string  $dates
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\WaterLog>
     */
    public function scopeConsumedAtBetween(Builder $query, array|string $dates): Builder
    {
        /** @var array<int, string> $datesArray */
        $datesArray = is_array($dates) ? $dates : explode(',', $dates);

        return $query->whereBetween('consumed_at', [
            Carbon::parse((string) $datesArray[0])->startOfDay(),
            Carbon::parse((string) ($datesArray[1] ?? $datesArray[0]))->endOfDay(),
        ]);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'consumed_at' => 'datetime',
        ];
    }
}
