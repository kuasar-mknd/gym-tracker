<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property float $weight
 * @property float|null $body_fat
 * @property \Illuminate\Support\Carbon $measured_at
 * @property string|null $notes
 * @property-read \App\Models\User $user
 */
class BodyMeasurement extends Model
{
    /** @use HasFactory<\Database\Factories\BodyMeasurementFactory> */
    use HasFactory;

    protected $fillable = [
        'weight',
        'body_fat',
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
            'measured_at' => 'date',
            'weight' => 'decimal:2',
            'body_fat' => 'decimal:2',
        ];
    }
}
