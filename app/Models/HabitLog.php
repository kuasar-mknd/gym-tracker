<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\ResolvesOwnerAtRouteBinding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $habit_id
 * @property int|null $user_id la copie denormalisee du proprietaire, pour qu'un index serve
 *                             a la fois le filtre et l'ordre
 * @property \Illuminate\Support\Carbon $date
 * @property string|null $notes
 * @property-read \App\Models\Habit $habit
 */
class HabitLog extends Model
{
    /** @use HasFactory<\Database\Factories\HabitLogFactory> */
    use HasFactory;

    use ResolvesOwnerAtRouteBinding;

    #[\Override]
    protected $fillable = [
        'habit_id',
        'date',
        'notes',
    ];

    #[\Override]
    protected function ownershipPath(): string
    {
        return 'habit';
    }

    /**
     * La copie denormalisee, posee avant l'ecriture.
     *
     * `user_id` n'est pas la verite — `habit_id` l'est, et porte la cascade.
     * D'ou la lecture par la CLEF plutot que par la relation : `$journal->habit`
     * rend l'instance mise en cache, donc l'ancienne habitude quand c'est
     * justement `habit_id` qui vient de changer.
     */
    #[\Override]
    protected static function booted(): void
    {
        static::saving(function (self $journal): void {
            if (! $journal->isDirty('habit_id') && $journal->user_id !== null) {
                return;
            }

            $proprietaire = Habit::whereKey($journal->habit_id)->value('user_id');
            $journal->user_id = is_numeric($proprietaire) ? (int) $proprietaire : null;
        });
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Habit, $this>
     */
    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<$this>  $query
     * @param  array<int, mixed>|mixed  $dates
     * @return \Illuminate\Database\Eloquent\Builder<$this>
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

    /**
     * `date:Y-m-d`, not `date`. A bare date cast serialises through Carbon's
     * toJSON, which reads the value in the app timezone and renders it in UTC:
     * a log for 2026-07-31 left here as "2026-07-30T22:00:00.000000Z". The week
     * grid compares that string to the plain Y-m-d it builds for each column,
     * so no day could ever match and no box was ever drawn as done — while the
     * counter beside it, reading logs.length, kept saying "3/7".
     *
     * The column holds a calendar day, not an instant. It must not move.
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
        ];
    }
}
