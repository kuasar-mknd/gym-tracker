<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BodyMeasurement extends Model
{
    use HasFactory;

    protected $fillable = [
        'weight',
        'measured_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'measured_at' => 'date',
            'weight' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
