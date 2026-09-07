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
        // Spatie QueryBuilder passe les arguments dans un tableau quand ils
        // viennent d'un seul paramètre de filtre, et un par un lorsqu'il est
        // configuré ainsi : les deux formes sont acceptées.
        $dates = is_array($dates[0]) ? $dates[0] : $dates;

        if (count($dates) >= 2) {
            return $query->whereBetween('date', [$dates[0], $dates[1]]);
        }

        return $query;
    }

    /**
     * `date:Y-m-d`, et non `date`. Un cast `date` nu sérialise par le `toJSON`
     * de Carbon, qui lit la valeur dans le fuseau de l'application et la rend en
     * UTC : un journal du 31/07/2026 partait d'ici en
     * « 2026-07-30T22:00:00.000000Z ». La grille de la semaine compare cette
     * chaîne au Y-m-d simple qu'elle fabrique pour chaque colonne, donc aucun
     * jour ne pouvait correspondre et aucune case n'était jamais cochée — tandis
     * que le compteur d'à côté, qui lit `logs.length`, affichait « 3/7 ».
     *
     * La colonne porte un jour de calendrier, pas un instant. Elle ne doit pas
     * bouger.
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
        ];
    }
}
