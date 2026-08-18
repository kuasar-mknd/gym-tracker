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
            // date:Y-m-d, as BodyPartMeasurement and DailyJournal already do.
            // A bare date cast renders the day in UTC, so a weigh-in saved on
            // 31 July was listed as 30 July — the day before, all year round.
            'measured_at' => 'date:Y-m-d',
            'weight' => 'decimal:2',
            'body_fat' => 'decimal:2',
        ];
    }
}
