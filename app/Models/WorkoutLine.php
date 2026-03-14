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

    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
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
        // If pre-loaded via batch method, return directly
        if (isset($this->attributes['recommended_values'])) {
            /** @var array{weight: float, reps: int, distance_km: float, duration_seconds: int} $values */
            $values = json_decode((string) $this->attributes['recommended_values'], true); // @phpstan-ignore cast.string

            return $values;
        }

        return $this->getRecommendedValues();
    }

    /**
     * Set recommended values directly (used by batch loading).
     *
     * @param  array{weight: float, reps: int, distance_km: float, duration_seconds: int}  $values
     */
    public function setRecommendedValuesAttribute(array $values): void
    {
        $this->attributes['recommended_values'] = json_encode($values);
    }

    /**
     * Batch-load recommended values for a collection of workout lines.
     * Replaces N+1 queries with 1-2 queries total.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, WorkoutLine>  $lines
     * @return array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}>
     */
    public static function batchRecommendedValues(\Illuminate\Database\Eloquent\Collection $lines, int $userId): array
    {
        $deps = self::getBatchDependencies($lines);

        if ($deps === null) {
            return [];
        }

        /** @var array{0: int, 1: array<int, mixed>, 2: \App\Models\Workout} $deps */
        [$workoutId, $exerciseIds, $workout] = $deps;

        $defaults = [
            'weight' => 0,
            'reps' => 10,
            'distance_km' => 0,
            'duration_seconds' => 30,
        ];

        $results = self::getResultsFromCacheOrFetch($exerciseIds, $userId, $workoutId, $workout, $defaults);

        foreach ($lines as $line) {
            $line->recommended_values = $results[$line->exercise_id] ?? $defaults;
        }

        return $results;
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

        // Try cache first
        $cacheKey = "recommended_values:{$this->workout->user_id}:{$this->exercise_id}:{$this->workout_id}";
        /** @var array{weight: float, reps: int, distance_km: float, duration_seconds: int}|null $cached */
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

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
            $values = [
                'weight' => 0,
                'reps' => 10,
                'distance_km' => 0,
                'duration_seconds' => 30,
            ];
            \Illuminate\Support\Facades\Cache::put($cacheKey, $values, 300);

            return $values;
        }

        $sets = $lastLine->sets;

        if ($sets->isEmpty()) {
            $values = [
                'weight' => 0,
                'reps' => 10,
                'distance_km' => 0,
                'duration_seconds' => 30,
            ];
            \Illuminate\Support\Facades\Cache::put($cacheKey, $values, 300);

            return $values;
        }

        // Find the most frequent combination of values
        $frequencies = $sets->groupBy(fn ($set): string => "{$set->weight}-{$set->reps}-{$set->distance_km}-{$set->duration_seconds}")->map->count();

        $mostFrequentKey = (string) $frequencies->sortDesc()->keys()->first();
        [$weight, $reps, $distance, $duration] = explode('-', $mostFrequentKey);

        $values = [
            'weight' => (float) $weight,
            'reps' => (int) $reps,
            'distance_km' => (float) $distance,
            'duration_seconds' => (int) $duration,
        ];

        \Illuminate\Support\Facades\Cache::put($cacheKey, $values, 300);

        return $values;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, WorkoutLine>  $lines
     * @return array{0: int, 1: array<int, mixed>, 2: \App\Models\Workout}|null
     */
    private static function getBatchDependencies(\Illuminate\Database\Eloquent\Collection $lines): ?array
    {
        if ($lines->isEmpty()) {
            return null;
        }

        $workoutId = $lines->first()->workout_id;
        $exerciseIds = $lines->pluck('exercise_id')->unique()->values()->all();

        if (count($exerciseIds) === 0 || $workoutId === null) {
            return null;
        }

        /** @var \App\Models\Workout|null $workout */
        $workout = Workout::find($workoutId);
        if (! $workout) {
            return null;
        }

        return [$workoutId, $exerciseIds, $workout];
    }

    /**
     * @param  array<int, mixed>  $exerciseIds
     * @param  array{weight: float, reps: int, distance_km: float, duration_seconds: int}  $defaults
     * @return array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}>
     */
    private static function getResultsFromCacheOrFetch(array $exerciseIds, int $userId, int $workoutId, Workout $workout, array $defaults): array
    {
        $results = [];
        $uncachedExerciseIds = [];

        foreach ($exerciseIds as $exerciseId) {
            $exerciseIdInt = (int) $exerciseId; // @phpstan-ignore cast.int
            $cacheKey = "recommended_values:{$userId}:{$exerciseIdInt}:{$workoutId}";
            /** @var array{weight: float, reps: int, distance_km: float, duration_seconds: int}|null $cached */
            $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if ($cached !== null) {
                $results[$exerciseIdInt] = $cached;
            } else {
                $uncachedExerciseIds[] = $exerciseId;
            }
        }

        if (count($uncachedExerciseIds) > 0) {
            $uncachedResults = self::fetchUncachedRecommendedValues($uncachedExerciseIds, $workoutId, $userId, $workout, $defaults);
            foreach ($uncachedResults as $exerciseIdInt => $values) {
                $results[$exerciseIdInt] = $values;
            }
        }

        return $results;
    }

    /**
     * @param  array<int, mixed>  $uncachedExerciseIds
     * @param  array{weight: float, reps: int, distance_km: float, duration_seconds: int}  $defaults
     * @return array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}>
     */
    private static function fetchUncachedRecommendedValues(array $uncachedExerciseIds, int $workoutId, int $userId, Workout $workout, array $defaults): array
    {
        $results = [];

        $latestSubquery = self::query()
            ->selectRaw('MAX(workout_lines.id) as latest_line_id')
            ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
            ->whereIn('workout_lines.exercise_id', $uncachedExerciseIds)
            ->where('workouts.user_id', $userId)
            ->where('workouts.id', '!=', $workoutId)
            ->where('workouts.started_at', '<', $workout->started_at)
            ->groupBy('workout_lines.exercise_id');

        $lastLines = self::query()
            ->whereIn('id', $latestSubquery)
            ->with('sets')
            ->get()
            ->keyBy('exercise_id');

        foreach ($uncachedExerciseIds as $exerciseId) {
            $exerciseIdInt = (int) $exerciseId; // @phpstan-ignore cast.int
            $lastLine = $lastLines->get($exerciseIdInt);

            if (! $lastLine || $lastLine->sets->isEmpty()) {
                $values = $defaults;
            } else {
                $sets = $lastLine->sets;
                $frequencies = $sets->groupBy(fn ($set): string => "{$set->weight}-{$set->reps}-{$set->distance_km}-{$set->duration_seconds}")->map->count();
                $mostFrequentKey = (string) $frequencies->sortDesc()->keys()->first();
                [$weight, $reps, $distance, $duration] = explode('-', $mostFrequentKey);

                $values = [
                    'weight' => (float) $weight,
                    'reps' => (int) $reps,
                    'distance_km' => (float) $distance,
                    'duration_seconds' => (int) $duration,
                ];
            }

            $cacheKey = "recommended_values:{$userId}:{$exerciseIdInt}:{$workoutId}";
            \Illuminate\Support\Facades\Cache::put($cacheKey, $values, 300);
            $results[$exerciseIdInt] = $values;
        }

        return $results;
    }
}
