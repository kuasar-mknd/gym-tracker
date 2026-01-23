<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
