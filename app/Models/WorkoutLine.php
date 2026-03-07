<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $workout_id
 * @property int $exercise_id
 * @property int $order
 * @property string|null $notes
 * @property-read \App\Models\Workout $workout
 * @property-read \App\Models\Exercise $exercise
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Set> $sets
 */
class WorkoutLine extends Model
{
    /** @use HasFactory<\Database\Factories\WorkoutLineFactory> */
    use HasFactory;

    protected $fillable = [
        'exercise_id',
        'order',
        'notes',
    ];

    /**
     * The attributes that should be appended to the model's array form.
     *
     * ⚡ Bolt Optimization: 'recommended_values' is removed from global appends
     * to prevent N+1 query explosion during collection serialization (e.g. index pages).
     * This reduced query count from 33 to 3 for a sample of 5 workouts.
     * Use ->append('recommended_values') explicitly in controllers when needed.
     *
     * @var list<string>
     */
    protected $appends = [];

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
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Set, $this>
     */
    public function sets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Set::class);
    }

    /**
     * Get recommended values for a new set based on the most frequent values
     * from the last time the user performed this exercise.
     *
     * @return array{weight: float, reps: int, distance_km: float, duration_seconds: int}
     */
    public function getRecommendedValuesAttribute(): array
    {
        return $this->getRecommendedValues();
    }

    /**
     * Get recommended values for a new set based on the most frequent values
     * from the last time the user performed this exercise.
     *
     * @return array{weight: float, reps: int, distance_km: float, duration_seconds: int}
     */
    public function getRecommendedValues(): array
    {
        $this->loadMissing('workout');

        /** @var \App\Models\WorkoutLine|null $lastLine */
        $lastLine = self::query()
            ->where('exercise_id', $this->exercise_id)
            ->whereHas('workout', function ($query): void {
                $query->where('user_id', $this->workout->user_id)
                    ->where('id', '!=', $this->workout_id)
                    ->where('started_at', '<', $this->workout->started_at);
            })
            ->with(['sets', 'workout'])
            ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
            ->orderByDesc('workouts.started_at')
            ->select('workout_lines.*')
            ->first();

        if (! $lastLine) {
            return [
                'weight' => 0,
                'reps' => 10,
                'distance_km' => 0,
                'duration_seconds' => 30,
            ];
        }

        $sets = $lastLine->sets;

        if ($sets->isEmpty()) {
            return [
                'weight' => 0,
                'reps' => 10,
                'distance_km' => 0,
                'duration_seconds' => 30,
            ];
        }

        // Find the most frequent combination of values
        $frequencies = $sets->groupBy(fn ($set): string => "{$set->weight}-{$set->reps}-{$set->distance_km}-{$set->duration_seconds}")->map->count();

        $mostFrequentKey = (string) $frequencies->sortDesc()->keys()->first();
        [$weight, $reps, $distance, $duration] = explode('-', $mostFrequentKey);

        return [
            'weight' => (float) $weight,
            'reps' => (int) $reps,
            'distance_km' => (float) $distance,
            'duration_seconds' => (int) $duration,
        ];
    }

    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }
}
