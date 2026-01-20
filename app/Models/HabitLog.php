<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HabitLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'habit_id',
        'date',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }

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
}
