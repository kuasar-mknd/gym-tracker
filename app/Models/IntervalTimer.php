<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IntervalTimerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntervalTimer extends Model
{
    /** @use HasFactory<IntervalTimerFactory> */
    use HasFactory;

    /**
     * Le plus long intervalle de travail, de repos ou d'échauffement, en
     * secondes : une heure. Au-delà, ce n'est plus un minuteur d'intervalles,
     * et la colonne (int) est loin.
     */
    public const int SECONDES_MAX_PAR_INTERVALLE = 3_600;

    /**
     * Le plus de tours : un EMOM d'une heure en compte soixante.
     */
    public const int TOURS_MAX = 100;

    #[\Override]
    protected $fillable = [
        'user_id',
        'name',
        'work_seconds',
        'rest_seconds',
        'rounds',
        'warmup_seconds',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'work_seconds' => 'integer',
            'rest_seconds' => 'integer',
            'rounds' => 'integer',
            'warmup_seconds' => 'integer',
        ];
    }
}
