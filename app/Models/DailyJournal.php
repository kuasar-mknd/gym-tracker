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
        'energy_level',
        'motivation_level',
        'nutrition_score',
        'training_intensity',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'mood_score' => 'integer',
            'sleep_quality' => 'integer',
            'stress_level' => 'integer',
            'energy_level' => 'integer',
            'motivation_level' => 'integer',
            'nutrition_score' => 'integer',
            'training_intensity' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
