<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PersonalRecordType;
use Database\Factories\PersonalRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $exercise_id
 * @property PersonalRecordType $type
 * @property float $value
 * @property float|null $secondary_value
 * @property int|null $workout_id
 * @property int|null $set_id
 * @property Carbon|null $achieved_at
 * @property-read User $user
 * @property-read Exercise $exercise
 * @property-read Workout|null $workout
 * @property-read Set|null $set
 */
class PersonalRecord extends Model
{
    /** @use HasFactory<PersonalRecordFactory> */
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Exercise, $this>
     */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /**
     * @return BelongsTo<Workout, $this>
     */
    public function workout(): BelongsTo
    {
        return $this->belongsTo(Workout::class);
    }

    /**
     * @return BelongsTo<Set, $this>
     */
    public function set(): BelongsTo
    {
        return $this->belongsTo(Set::class);
    }

    protected function casts(): array
    {
        return [
            'type' => PersonalRecordType::class,
            'achieved_at' => 'datetime',
            'value' => 'decimal:2',
            'secondary_value' => 'decimal:2',
        ];
    }
}
