<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BodyPartMeasurement extends Model
{
    use HasFactory;

    protected $fillable = [
        'part',
        'value',
        'measured_at',
    ];

    protected $casts = [
        'measured_at' => 'date',
        'value' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
