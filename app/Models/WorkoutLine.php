<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\ResolvesOwnerAtRouteBinding;
use App\Services\RecommendedValuesService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $workout_id
 * @property int $exercise_id
 * @property int $user_id la copie denormalisee du proprietaire, pour qu'un index serve
 *                        filtre et ordre sans jointure ; `workout_id` reste la verite
 * @property \Illuminate\Support\Carbon|null $workout_started_at
 * @property int $order
 * @property string|null $notes
 * @property string|null $idempotency_key names the client attempt that created this row, so a
 *                                        replayed create returns it instead of making a second one. Deliberately absent from
 *                                        $fillable: it identifies the attempt, never something a payload may set.
 * @property-read \App\Models\Workout $workout
 * @property-read \App\Models\Exercise $exercise
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Set> $sets
 */
class WorkoutLine extends Model
{
    /** @use HasFactory<\Database\Factories\WorkoutLineFactory> */
    use HasFactory;

    use ResolvesOwnerAtRouteBinding;

    #[\Override]
    protected $fillable = [
        'exercise_id',
        'order',
        'notes',
    ];

    /**
     * @var list<string>
     */
    #[\Override]
    protected $appends = [];

    /**
     * La seance porteuse n'est pas terminee.
     *
     * Repose sur `ownerUserId()` ayant deja etabli un proprietaire : une chaine
     * rompue rend `ended_at` nul, ce qui se lirait ici comme « en cours ». Les
     * policies posent les deux questions ensemble, dans cet ordre.
     */
    public function ownerWorkoutIsOngoing(): bool
    {
        return $this->ownershipValue('owner_ended_at') === null;
    }

    #[\Override]
    protected function ownershipPath(): string
    {
        return 'workout';
    }

    /**
     * @return array<string, string>
     */
    protected function ownershipColumns(): array
    {
        return ['owner_user_id' => 'user_id', 'owner_ended_at' => 'ended_at'];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Workout, $this>
     */
    public function workout(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Workout::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Exercise, $this>
     */
    public function exercise(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /**
     * The sets of this line, in the order they were added.
     *
     * Ordered explicitly, because without an ORDER BY the database is free to
     * hand back whatever the index it picked happens to give. This table carries
     * sets_workout_line_id_weight_reps_index (workout_line_id, weight, reps)
     * alongside the plain workout_line_id one, and when the optimiser chooses it
     * the sets come back sorted BY WEIGHT — so correcting the weight of a set
     * moved it up or down the list on the next load. Creation order is the only
     * order a set has, and every caller of this relation renders it as such: the
     * session screen, the API, the exercise history, and the template copied out
     * of a workout.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Set, $this>
     */
    public function sets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Set::class)->orderBy('order')->orderBy('id');
    }

    /**
     * @return array{weight: float, reps: int, distance_km: float, duration_seconds: int}
     */
    public function getRecommendedValuesAttribute(): array
    {
        if (! isset($this->attributes['recommended_values'])) {
            return app(RecommendedValuesService::class)->getRecommendedValues($this);
        }

        /** @var string|null $val */
        $val = $this->attributes['recommended_values'];
        /** @var array{weight: float, reps: int, distance_km: float, duration_seconds: int} $decoded */
        $decoded = json_decode((string) $val, true);

        return is_array($decoded) ? $decoded : ['weight' => 0.0, 'reps' => 0, 'distance_km' => 0.0, 'duration_seconds' => 0];
    }

    /**
     * @param  array{weight: float, reps: int, distance_km: float, duration_seconds: int}  $values
     */
    public function setRecommendedValuesAttribute(array $values): void
    {
        $this->attributes['recommended_values'] = json_encode($values);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, WorkoutLine>  $lines
     * @return array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}>
     */
    public static function batchRecommendedValues(\Illuminate\Database\Eloquent\Collection $lines, int $userId): array
    {
        return app(RecommendedValuesService::class)->batchRecommendedValues($lines, $userId);
    }

    #[\Override]
    protected static function booted(): void
    {
        $clearCache = function (self $line): void {
            /*
             * On teste la RELATION, pas la cle etrangere.
             *
             * `workout_lines.workout_id` est NOT NULL et les identifiants
             * commencent a 1 : `if ($line->workout_id)` etait donc toujours
             * vrai. Le garde avait l'air de proteger la ligne suivante, qui
             * dereference `$line->workout` — et c'est ce dereferencement, lui,
             * qui peut echouer si la seance a disparu entre-temps.
             */
            $workout = $line->workout;

            if ($workout === null) {
                return;
            }

            app(\App\Services\ActiveWorkoutService::class)->forget($workout->user_id);
        };

        /*
         * La copie denormalisee, posee avant l'ecriture.
         *
         * `user_id` et `workout_started_at` ne sont pas la verite — `workout_id`
         * l'est. Ils existent pour qu'un index puisse servir a la fois le filtre
         * et l'ordre de « la derniere fois que cet utilisateur a fait cet
         * exercice », question qui sinon materialise toute la jointure.
         */
        static::saving(function (self $line): void {
            $seance = $line->workout;

            if ($seance === null) {
                return;
            }

            $line->user_id = $seance->user_id;
            $line->workout_started_at = $seance->started_at;
        });

        static::saved($clearCache);
        static::deleted($clearCache);

        /**
         * Gives back the volume of the sets this line is about to take with it.
         *
         * sets.workout_line_id is ON DELETE CASCADE, so the database removes
         * those rows itself and Eloquent never hears about it — Set::deleted
         * does not fire, and the volume they contributed stays in
         * users.total_volume and workouts.workout_volume for good. Every
         * exercise ever removed from a session has been inflating those two
         * counters since.
         *
         * Summed in one query and released once, rather than deleting each set
         * through the model: the rows are going regardless, and the counters
         * only care about the total.
         */
        static::deleted(function (self $line): void {
            $line->workout?->recomputeVolume();
        });
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'workout_started_at' => 'datetime',
        ];
    }
}
