<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $habit_id
 * @property \Illuminate\Support\Carbon $date
 * @property string|null $notes
 * @property-read \App\Models\Habit $habit
 */
class HabitLog extends Model
{
    /** @use HasFactory<\Database\Factories\HabitLogFactory> */
    use HasFactory;

    protected $fillable = [
        'habit_id',
        'date',
        'notes',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Habit, $this>
     */
    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<$this>  $query
     * @return \Illuminate\Database\Eloquent\Builder<$this>
     */
    public function scopeWhereDateBetween(Builder $query, mixed ...$dates): Builder
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
