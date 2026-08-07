<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $name
 * @property float $workout_volume
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 * @property string|null $notes
 * @property-read \App\Models\User $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\WorkoutLine> $workoutLines
 */
class Workout extends Model
{
    /** @use HasFactory<\Database\Factories\WorkoutFactory> */
    use HasFactory, LogsActivity;

    #[\Override]
    protected $fillable = [
        'name',
        'started_at',
        'ended_at',
        'notes',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\WorkoutLine, $this>
     */
    public function workoutLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WorkoutLine::class)->orderBy('order');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['started_at', 'ended_at', 'name'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'workout_volume' => 'float',
        ];
    }

    /**
     * Recomputes this session's volume from its sets, and moves the user's
     * lifetime total by the difference.
     *
     * The counters used to be maintained by accumulating a delta per set save:
     * new volume minus the volume that set had when the model was loaded. Two
     * requests touching the same set at once — the weight and the reps of one
     * row, which the debounce sends as separate PATCHes — each computed their
     * delta from the same snapshot. Applying both left the totals adrift from
     * the sets they claim to describe, permanently and invisibly.
     *
     * Recomputing from the rows costs one aggregate instead of an increment and
     * cannot drift, whatever order the writes land in.
     */
    public function recomputeVolume(): void
    {
        $total = (float) $this->workoutLines()
            ->join('sets', 'sets.workout_line_id', '=', 'workout_lines.id')
            ->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(sets.weight, 0) * COALESCE(sets.reps, 0)'));

        // Read back from the table: increment() writes straight to the database
        // and leaves whatever instance the caller is holding behind.
        $stored = $this->newQuery()->whereKey($this->getKey())->value('workout_volume');
        $delta = $total - (is_numeric($stored) ? (float) $stored : 0.0);

        if ($delta === 0.0) {
            return;
        }

        $this->newQuery()->whereKey($this->getKey())->update(['workout_volume' => $total]);
        $this->user?->increment('total_volume', $delta);
    }

    #[\Override]
    protected static function booted(): void
    {
        $clearCache = function (self $workout): void {
            \Illuminate\Support\Facades\Cache::forget("user_active_workout_{$workout->user_id}");
        };

        static::saved($clearCache);
        static::deleted($clearCache);

        /**
         * Same cascade, one level up. Deleting a workout removes its lines and,
         * through them, every set — all in the database, none of it through
         * Eloquent. The line hook below never fires either, so the user's
         * lifetime total kept the volume of a session they had deleted.
         *
         * The workout already carries the aggregate, so releasing it is exact
         * and costs nothing to compute.
         */
        static::deleting(function (self $workout): void {
            /**
             * Read back from the table, not from the attribute. The counter is
             * maintained with increment(), which writes straight to the database
             * and leaves whatever instance the caller is holding behind — an
             * in-memory workout can easily still say zero.
             */
            $stored = $workout->newQuery()->whereKey($workout->getKey())->value('workout_volume');
            $volume = is_numeric($stored) ? (float) $stored : 0.0;

            if ($volume !== 0.0) {
                $workout->user?->decrement('total_volume', $volume);
            }
        });
    }
}
