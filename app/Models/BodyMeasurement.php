<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BodyMeasurementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property float $weight
 * @property float|null $body_fat
 * @property Carbon $measured_at
 * @property string|null $notes
 * @property-read User $user
 */
class BodyMeasurement extends BaseMeasurement
{
    /** @use HasFactory<BodyMeasurementFactory> */
    use HasFactory;

    protected $fillable = [
        'weight',
        'body_fat',
        'measured_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'measured_at' => 'date',
            'weight' => 'decimal:2',
            'body_fat' => 'decimal:2',
        ];
    }
}
