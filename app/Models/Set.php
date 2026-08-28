<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\ResolvesOwnerAtRouteBinding;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $workout_line_id
 * @property int|null $user_id la copie denormalisee du proprietaire, pour qu'un index
 *                             serve a la fois le filtre et l'ordre
 * @property float|null $weight
 * @property int|null $reps
 * @property int|null $duration_seconds
 * @property float|null $distance_km
 * @property bool $is_warmup
 * @property bool $is_completed
 * @property string|null $idempotency_key names the client attempt that created this row, so a
 *                                        replayed create returns it instead of making a second one. Deliberately absent from
 *                                        $fillable: it identifies the attempt, never something a payload may set.
 * @property-read \App\Models\WorkoutLine $workoutLine
 * @property-read \App\Models\PersonalRecord|null $personalRecord
 */
class Set extends Model
{
    /** @use HasFactory<\Database\Factories\SetFactory> */
    use HasFactory;

    use ResolvesOwnerAtRouteBinding;

    #[\Override]
    protected $fillable = [
        'workout_line_id',
        'weight',
        'reps',
        'duration_seconds',
        'distance_km',
        'is_warmup',
        'is_completed',
    ];

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
        return 'workoutLine.workout';
    }

    /**
     * @return array<string, string>
     */
    protected function ownershipColumns(): array
    {
        return ['owner_user_id' => 'user_id', 'owner_ended_at' => 'ended_at'];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\WorkoutLine, $this>
     */
    public function workoutLine(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WorkoutLine::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<\App\Models\PersonalRecord, $this>
     */
    public function personalRecord(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PersonalRecord::class);
    }

    /**
     * Update the total volume for the user and the workout.
     */
    public function updateVolumes(): void
    {
        $this->syncVolumes();
    }

    /**
     * Decrement the total volume for the user and the workout.
     */
    public function decrementVolumes(): void
    {
        $this->syncVolumes();
    }

    /**
     * Brings both counters back in line with the sets that exist.
     *
     * These used to accumulate a delta per save — the set's new volume minus the
     * volume it had when the model was loaded. Two requests touching the same
     * row at once, which is exactly what the per-field debounce produces when a
     * user corrects a weight and a rep count together, each computed their delta
     * from the same snapshot. Both were applied, and the totals drifted away
     * from the sets they claim to describe, permanently and with nothing to show
     * for it.
     */
    private function syncVolumes(): void
    {
        $this->loadMissing('workoutLine.workout.user');

        $this->workoutLine?->workout?->recomputeVolume();
    }

    #[\Override]
    protected static function booted(): void
    {
        /*
         * La copie denormalisee, posee avant l'ecriture.
         *
         * `user_id` n'est pas la verite — `workout_line_id` l'est, et porte la
         * cascade. Elle est derivee de la CLEF et non de la relation :
         * `$serie->workoutLine` rend l'instance mise en cache, donc l'ancienne
         * ligne quand c'est justement `workout_line_id` qui vient de changer.
         *
         * Recalculee seulement quand la clef bouge : une serie dont on ne
         * corrige que le poids n'emet aucune requete de plus.
         */
        static::saving(function (Set $set): void {
            if (! $set->isDirty('workout_line_id') && $set->user_id !== null) {
                return;
            }

            $proprietaire = WorkoutLine::whereKey($set->workout_line_id)->value('user_id');
            $set->user_id = is_numeric($proprietaire) ? (int) $proprietaire : null;
        });

        static::saved(function (Set $set): void {
            $set->updateVolumes();
        });

        static::deleted(function (Set $set): void {
            $set->decrementVolumes();
        });
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'is_warmup' => 'boolean',
            'is_completed' => 'boolean',
            'weight' => 'float',
            'distance_km' => 'float',
            'duration_seconds' => 'integer',
        ];
    }
}
