<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BodyPartMeasurementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $part
 * @property string $value
 * @property string $unit
 * @property Carbon $measured_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class BodyPartMeasurement extends BaseMeasurement
{
    /** @use HasFactory<BodyPartMeasurementFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'part',
        'value',
        'unit',
        'measured_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'measured_at' => 'date:Y-m-d',
            'value' => 'decimal:2',
        ];
    }
}
