<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Service for calculating and managing recommended workout values.
 *
 * This service determines the optimal weight, reps, distance, and duration
 * for a specific exercise within a workout line, based on the user's past
 * performance data. It uses caching to improve performance for frequently
 * requested recommendations.
 */
final class RecommendedValuesService
{
    /**
     * Get the recommended values for a given workout line.
     *
     * Analyzes previous workout history for the same exercise and user to
     * suggest the most likely weight, repetitions, distance, and duration.
     * Caches the result to minimize database queries.
     *
     * @param  WorkoutLine  $line  The workout line requiring recommended values.
     * @return array{weight: float, reps: int, distance_km: float, duration_seconds: int}
     */
    public function getRecommendedValues(WorkoutLine $line): array
    {
        $line->loadMissing('workout');
        $workout = $line->workout;

        if (! $workout) {
            return $this->getDefaultValues();
        }

        $cacheKey = "recommended_values:{$workout->user_id}:{$line->exercise_id}:{$line->workout_id}";

        /** @var array{weight: float, reps: int, distance_km: float, duration_seconds: int}|null $cached */
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // ⚡ Bolt: PERFORMANCE OPTIMIZATION
        // Replaced redundant whereHas('workout') subquery with direct WHERE clauses
        // on the already joined 'workouts' table to prevent an unnecessary EXISTS subquery execution.
        $lastLine = WorkoutLine::query()
            ->with(['sets'])
            ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
            ->where('workout_lines.exercise_id', $line->exercise_id)
            ->where('workouts.user_id', $workout->user_id)
            ->where('workouts.id', '!=', $workout->id)
            ->where('workouts.started_at', '<', $workout->started_at)
            ->orderByDesc('workouts.started_at')
            ->select('workout_lines.*')
            ->first();

        $values = $this->calculateFromLine($lastLine);
        Cache::put($cacheKey, $values, 300);

        return $values;
    }

    /**
     * Batch-load recommended values for a collection of workout lines.
     *
     * Efficiently resolves recommended values for multiple workout lines
     * simultaneously, utilizing caching and minimizing database lookups.
     * Automatically applies these resolved values back onto the provided models.
     *
     * @param  Collection<int, WorkoutLine>  $lines  A collection of workout lines to populate.
     * @param  int  $userId  The ID of the user the lines belong to.
     * @return array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}> A dictionary mapping exercise IDs to their recommended values.
     */
    public function batchRecommendedValues(Collection $lines, int $userId): array
    {
        if ($lines->isEmpty()) {
            return [];
        }

        $workoutId = $lines->first()->workout_id;
        /** @var array<int, int> $exerciseIds */
        $exerciseIds = $lines->pluck('exercise_id')->unique()->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->values()->all();

        $workout = $this->resolveWorkout($workoutId, $exerciseIds);
        if (! $workout) {
            return [];
        }

        $defaults = $this->getDefaultValues();
        $results = $this->getResultsFromCacheOrFetch($exerciseIds, $userId, (int) $workoutId, $workout);

        $this->applyRecommendedValuesToLines($lines, $results, $defaults);

        return $results;
    }

    /**
     * Resolve the Workout model associated with the given ID.
     *
     * Ensures that the workout ID is valid and that there are related
     * exercise IDs before attempting to retrieve the model.
     *
     * @param  int|null  $workoutId  The ID of the workout to resolve.
     * @param  array<int, int>  $exerciseIds  The array of associated exercise IDs.
     * @return Workout|null The resolved Workout model, or null if invalid or missing.
     */
    private function resolveWorkout(?int $workoutId, array $exerciseIds): ?Workout
    {
        if ($workoutId === null || count($exerciseIds) === 0) {
            return null;
        }

        return Workout::find($workoutId);
    }

    /**
     * Apply calculated recommended values directly to the WorkoutLine models.
     *
     * Sets a non-persisted 'recommended_values' attribute on each line,
     * falling back to default values if no recommendation is found.
     *
     * @param  Collection<int, WorkoutLine>  $lines  The lines to update.
     * @param  array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}>  $results  The calculated results keyed by exercise ID.
     * @param  array{weight: float, reps: int, distance_km: float, duration_seconds: int}  $defaults  The default fallback values.
     */
    private function applyRecommendedValuesToLines(Collection $lines, array $results, array $defaults): void
    {
        foreach ($lines as $line) {
            $line->setRecommendedValuesAttribute($results[$line->exercise_id] ?? $defaults);
        }
    }

    /**
     * Get the default baseline values for a workout line.
     *
     * @return array{weight: float, reps: int, distance_km: float, duration_seconds: int} Default set parameters.
     */
    private function getDefaultValues(): array
    {
        return [
            'weight' => 0.0,
            'reps' => 10,
            'distance_km' => 0.0,
            'duration_seconds' => 30,
        ];
    }

    /**
     * Calculate recommended values based on a previous workout line's sets.
     *
     * Determines the most frequent combination of weight, reps, distance,
     * and duration used across all sets in that previous line.
     *
     * @param  WorkoutLine|null  $lastLine  The most recent previous workout line for the exercise.
     * @return array{weight: float, reps: int, distance_km: float, duration_seconds: int} The most commonly used set parameters.
     */
    private function calculateFromLine(?WorkoutLine $lastLine): array
    {
        if (! $lastLine || $lastLine->sets->isEmpty()) {
            return $this->getDefaultValues();
        }

        $sets = $lastLine->sets;
        $frequencies = $sets->groupBy(fn ($set): string => "{$set->weight}-{$set->reps}-{$set->distance_km}-{$set->duration_seconds}")
            ->map(fn ($group): int => $group->count());

        $mostFrequentKey = (string) $frequencies->sortDesc()->keys()->first();
        [$weight, $reps, $distance, $duration] = explode('-', $mostFrequentKey);

        return [
            'weight' => (float) $weight,
            'reps' => (int) $reps,
            'distance_km' => (float) $distance,
            'duration_seconds' => (int) $duration,
        ];
    }

    /**
     * Retrieve recommended values from cache, or fetch them if missing.
     *
     * Iterates through required exercise IDs. Yields cached results if available;
     * otherwise, delegates to fetch the uncached values from the database and
     * merges them into the final result set.
     *
     * @param  array<int, int>  $exerciseIds  The list of exercise IDs to retrieve values for.
     * @param  int  $userId  The user ID associated with the recommendations.
     * @param  int  $workoutId  The current workout ID.
     * @param  Workout  $workout  The current workout instance.
     * @return array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}> Results mapped by exercise ID.
     */
    private function getResultsFromCacheOrFetch(array $exerciseIds, int $userId, int $workoutId, Workout $workout): array
    {
        $results = [];
        $uncachedExerciseIds = [];

        foreach ($exerciseIds as $exerciseId) {
            $exerciseIdInt = (int) $exerciseId;
            $cacheKey = "recommended_values:{$userId}:{$exerciseIdInt}:{$workoutId}";

            /** @var array{weight: float, reps: int, distance_km: float, duration_seconds: int}|null $cached */
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                $results[$exerciseIdInt] = $cached;
            } else {
                $uncachedExerciseIds[] = $exerciseIdInt;
            }
        }

        if (count($uncachedExerciseIds) > 0) {
            $uncachedResults = $this->fetchUncachedRecommendedValues($uncachedExerciseIds, $workoutId, $userId, $workout);
            foreach ($uncachedResults as $exerciseIdInt => $values) {
                $results[$exerciseIdInt] = $values;
            }
        }

        return $results;
    }

    /**
     * Fetch un-cached recommended values directly from the database.
     *
     * Performs a single query to find the latest previous workout line for each
     * requested exercise, calculates the recommended values from those lines,
     * caches the individual results, and returns them.
     *
     * @param  array<int, int>  $uncachedExerciseIds  Exercise IDs lacking cached data.
     * @param  int  $workoutId  The current workout ID (to exclude from search).
     * @param  int  $userId  The user ID to constrain the search.
     * @param  Workout  $workout  The current workout to establish the timeline limit.
     * @return array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}> Newly calculated results mapped by exercise ID.
     */
    private function fetchUncachedRecommendedValues(array $uncachedExerciseIds, int $workoutId, int $userId, Workout $workout): array
    {
        $results = [];

        $latestSubquery = WorkoutLine::query()
            ->selectRaw('MAX(workout_lines.id) as latest_line_id')
            ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
            ->whereIn('workout_lines.exercise_id', $uncachedExerciseIds)
            ->where('workouts.user_id', $userId)
            ->where('workouts.id', '!=', $workoutId)
            ->where('workouts.started_at', '<', $workout->started_at)
            ->groupBy('workout_lines.exercise_id');

        $lastLines = WorkoutLine::query()
            ->whereIn('id', $latestSubquery)
            ->with('sets')
            ->get()
            ->keyBy('exercise_id');

        $cacheData = [];
        foreach ($uncachedExerciseIds as $exerciseId) {
            $lastLine = $lastLines->get($exerciseId);
            $values = $this->calculateFromLine($lastLine);

            $cacheKey = "recommended_values:{$userId}:{$exerciseId}:{$workoutId}";
            $cacheData[$cacheKey] = $values;
            $results[$exerciseId] = $values;
        }

        if (count($cacheData) > 0) {
            Cache::putMany($cacheData, 300);
        }

        return $results;
    }
}
