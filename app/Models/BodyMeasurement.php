<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property int $user_id
 * @property numeric-string $weight
 * @property numeric-string|null $body_fat
 * @property \Illuminate\Support\Carbon $measured_at
 * @property string|null $notes
 * @property-read \App\Models\User $user
 */
class BodyMeasurement extends BaseMeasurement
{
    /** @use HasFactory<\Database\Factories\BodyMeasurementFactory> */
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'weight',
        'body_fat',
        'measured_at',
        'notes',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            // date:Y-m-d, comme le font déjà BodyPartMeasurement et
            // DailyJournal. Un cast date nu rend le jour en UTC : une pesée
            // enregistrée le 31 juillet était listée au 30 — la veille, toute
            // l'année.
            'measured_at' => 'date:Y-m-d',
            'weight' => 'decimal:2',
            'body_fat' => 'decimal:2',
        ];
    }
}
