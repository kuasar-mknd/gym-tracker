<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property int $exercise_id
 * @property string $type
 * @property float $value
 * @property float|null $secondary_value
 * @property int|null $workout_id
 * @property int|null $set_id
 * @property \Illuminate\Support\Carbon|null $achieved_at
 * @property-read \App\Models\User|null $user
 * @property-read \App\Models\Exercise|null $exercise
 * @property-read \App\Models\Workout|null $workout
 * @property-read \App\Models\Set|null $set
 */
class PersonalRecord extends Model
{
    /** @use HasFactory<\Database\Factories\PersonalRecordFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'exercise_id',
        'type',
        'value',
        'secondary_value',
        'workout_id',
        'set_id',
        'achieved_at',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Exercise, $this>
     */
    public function exercise(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Workout, $this>
     */
    public function workout(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Workout::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Set, $this>
     */
    public function set(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Set::class);
    }

    protected function casts(): array
    {
        return [
            'achieved_at' => 'datetime',
            'value' => 'decimal:2',
            'secondary_value' => 'decimal:2',
        ];
    }
}
