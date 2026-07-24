<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\HabitLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $habit_id
 * @property Carbon $date
 * @property string|null $notes
 * @property-read Habit $habit
 */
class HabitLog extends Model
{
    /** @use HasFactory<HabitLogFactory> */
    use HasFactory;

    protected $fillable = [
        'habit_id',
        'date',
        'notes',
    ];

    /**
     * @return BelongsTo<Habit, $this>
     */
    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @param  array<int, mixed>|mixed  $dates
     * @return Builder<$this>
     */
    public function scopeWhereDateBetween(Builder $query, ...$dates): Builder
    {
        // Spatie QueryBuilder passes arguments as an array if they come from a single filter parameter,
        // or as individual arguments if configured that way.
        // To be safe and handle the array wrapper often sent by Spatie:
        $dates = is_array($dates[0]) ? $dates[0] : $dates;

        if (count($dates) >= 2) {
            return $query->whereBetween('date', [$dates[0], $dates[1]]);
        }

        return $query;
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }
}
