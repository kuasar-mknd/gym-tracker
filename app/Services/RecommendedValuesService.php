<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

final class RecommendedValuesService
{
    /**
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

        $lastLine = WorkoutLine::query()
            ->where('exercise_id', $line->exercise_id)
            ->whereHas('workout', function ($query) use ($workout): void {
                $query->where('user_id', $workout->user_id)
                    ->where('id', '!=', $workout->id)
                    ->where('started_at', '<', $workout->started_at);
            })
            ->with(['sets'])
            ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
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
     * @param  Collection<int, WorkoutLine>  $lines
     * @return array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}>
     */
    public function batchRecommendedValues(Collection $lines, int $userId): array
    {
        if ($lines->isEmpty()) {
            return [];
        }

        $workoutId = $lines->first()->workout_id;
        $exerciseIds = $lines->pluck('exercise_id')->unique()->values()->all();

        if (count($exerciseIds) === 0 || $workoutId === null) {
            return [];
        }

        $workout = Workout::find($workoutId);
        if (! $workout) {
            return [];
        }

        $defaults = $this->getDefaultValues();
        $results = $this->getResultsFromCacheOrFetch($exerciseIds, $userId, (int) $workoutId, $workout, $defaults);

        foreach ($lines as $line) {
            $line->setRecommendedValuesAttribute($results[$line->exercise_id] ?? $defaults);
        }

        return $results;
    }

    /**
     * @return array{weight: float, reps: int, distance_km: float, duration_seconds: int}
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
     * @return array{weight: float, reps: int, distance_km: float, duration_seconds: int}
     */
    private function calculateFromLine(?WorkoutLine $lastLine): array
    {
        if (! $lastLine || $lastLine->sets->isEmpty()) {
            return $this->getDefaultValues();
        }

        $sets = $lastLine->sets;
        $frequencies = $sets->groupBy(fn ($set): string => "{$set->weight}-{$set->reps}-{$set->distance_km}-{$set->duration_seconds}")
            ->map->count();

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
     * @param  array<int, mixed>  $exerciseIds
     * @param  array{weight: float, reps: int, distance_km: float, duration_seconds: int}  $defaults
     * @return array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}>
     */
    private function getResultsFromCacheOrFetch(array $exerciseIds, int $userId, int $workoutId, Workout $workout, array $defaults): array
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
            $uncachedResults = $this->fetchUncachedRecommendedValues($uncachedExerciseIds, $workoutId, $userId, $workout, $defaults);
            foreach ($uncachedResults as $exerciseIdInt => $values) {
                $results[$exerciseIdInt] = $values;
            }
        }

        return $results;
    }

    /**
     * @param  array<int>  $uncachedExerciseIds
     * @param  array{weight: float, reps: int, distance_km: float, duration_seconds: int}  $defaults
     * @return array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}>
     */
    private function fetchUncachedRecommendedValues(array $uncachedExerciseIds, int $workoutId, int $userId, Workout $workout, array $defaults): array
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

        foreach ($uncachedExerciseIds as $exerciseId) {
            $lastLine = $lastLines->get($exerciseId);
            $values = $this->calculateFromLine($lastLine);

            $cacheKey = "recommended_values:{$userId}:{$exerciseId}:{$workoutId}";
            Cache::put($cacheKey, $values, 300);
            $results[$exerciseId] = $values;
        }

        return $results;
    }
}
