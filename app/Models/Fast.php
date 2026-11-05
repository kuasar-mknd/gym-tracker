<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\InstantAvecSonDecalage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property \Illuminate\Support\Carbon $start_time
 * @property int|null $start_time_offset le décalage de `start_time`, en minutes (#2159), que le cast
 *                                       `InstantAvecSonDecalage` écrit et lit ; nul sur une ligne d'avant
 * @property \Illuminate\Support\Carbon|null $end_time
 * @property int|null $end_time_offset le décalage de `end_time`, en minutes (#2159)
 */
class Fast extends Model
{
    /** @use HasFactory<\Database\Factories\FastFactory> */
    use HasFactory;

    /**
     * La durée cible la plus longue d'un jeûne, en minutes : une semaine. Le
     * plus long type proposé (48:0) en fait 2 880.
     */
    public const int DUREE_CIBLE_MAX_MINUTES = 10_080;

    #[\Override]
    protected $fillable = [
        'user_id',
        'start_time',
        'end_time',
        'target_duration_minutes',
        'type',
        'status',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Le décalage du début et de la fin ne sort pas du modèle : l'instant
     * qu'il sert (`…Z`) le porte déjà, et la page du jeûne le lit.
     *
     * @var list<string>
     */
    #[\Override]
    protected $hidden = [
        'start_time_offset',
        'end_time_offset',
    ];

    /**
     * Le début et la fin s'écrivent avec leur décalage (#2159) : relu à
     * l'heure murale seule, un début pris dans la première occurrence de
     * l'heure que le passage à l'heure d'hiver répète revenait une heure plus
     * tard, et la page comptait d'abord un écoulé négatif.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'start_time' => InstantAvecSonDecalage::class.':start_time_offset',
            'end_time' => InstantAvecSonDecalage::class.':end_time_offset',
            'target_duration_minutes' => 'integer',
        ];
    }
}
