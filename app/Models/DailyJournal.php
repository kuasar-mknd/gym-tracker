<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyJournal extends Model
{
    use HasFactory;

    protected $fillable = [
        'date',
        'content',
        'mood_score',
        'sleep_quality',
        'stress_level',
    ];

    protected $casts = [
        'date' => 'date',
        'mood_score' => 'integer',
        'sleep_quality' => 'integer',
        'stress_level' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
