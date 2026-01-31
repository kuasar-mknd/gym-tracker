<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $part
 * @property float $value
 * @property string $unit
 * @property \Illuminate\Support\Carbon $measured_at
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 */
class BodyPartMeasurement extends Model
{
    /** @use HasFactory<\Database\Factories\BodyPartMeasurementFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'part',
        'value',
        'unit',
        'measured_at',
        'notes',
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
            'measured_at' => 'date:Y-m-d',
            'value' => 'decimal:2',
        ];
    }
}
