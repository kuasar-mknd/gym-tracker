<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une prise d'eau, horodatee.
 *
 * Le bloc de proprietes manquait, la ou la plupart des modeles voisins en
 * portent un. Sans lui, l'analyse statique donnait `string` a `consumed_at`
 * — la colonne est un `timestamp` — et le `@var \Carbon\Carbon` pose sur le
 * resultat dans `FetchWaterHistoryAction` contredisait ce type au lieu de le
 * preciser (#1482). C'est ici qu'il fallait le dire, une fois, pour tous les
 * appelants.
 *
 * @property int $id
 * @property int $user_id
 * @property int $amount millilitres
 * @property \Illuminate\Support\Carbon $consumed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 */
class WaterLog extends Model
{
    /** @use HasFactory<\Database\Factories\WaterLogFactory> */
    use HasFactory;

    #[\Override]
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
    public function scopeConsumedAtBetween(Builder $query, $dates): Builder
    {
        /** @var array<int, string> $datesArray */
        $datesArray = is_array($dates) ? $dates : explode(',', (string) $dates);

        return $query->whereBetween('consumed_at', [
            Carbon::parse((string) $datesArray[0])->startOfDay(),
            Carbon::parse((string) ($datesArray[1] ?? $datesArray[0]))->endOfDay(),
        ]);
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'consumed_at' => 'datetime',
        ];
    }
}
