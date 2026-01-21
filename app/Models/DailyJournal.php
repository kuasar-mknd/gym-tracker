<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $date
 * @property string|null $content
 * @property int|null $mood_score
 * @property int|null $sleep_quality
 * @property int|null $stress_level
 * @property int|null $energy_level
 * @property int|null $motivation_level
 * @property int|null $nutrition_score
 * @property int|null $training_intensity
 * @property-read \App\Models\User $user
 */
class DailyJournal extends Model
{
    /** @use HasFactory<\Database\Factories\DailyJournalFactory> */
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
}
