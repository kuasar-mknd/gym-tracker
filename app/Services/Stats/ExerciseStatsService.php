<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\DTOs\Stats\Exercise1RMProgressPoint;
use App\DTOs\Stats\MuscleDistributionStat;
use App\Models\Set;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

final class ExerciseStatsService
{
    /**
     * @return array<int, MuscleDistributionStat>
     */
    public function getMuscleDistribution(User $user, int $days = 30): array
    {
        return Cache::remember(
            "stats.muscle_dist.{$user->id}.{$days}",
            now()->addMinutes(30),
            fn (): array => Set::query()
                ->toBase()
                ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
                ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
                ->join('exercises', 'workout_lines.exercise_id', '=', 'exercises.id')
                ->where('workouts.user_id', $user->id)
                ->where('workouts.started_at', '>=', now()->subDays($days))
                ->selectRaw('exercises.category, SUM(sets.weight * sets.reps) as volume')
                ->groupBy('exercises.category')
                ->get()
                ->map(fn (\stdClass $row): MuscleDistributionStat => new MuscleDistributionStat(
                    (string) ($row->category ?? 'Unknown'),
                    (float) ($row->volume ?? 0.0),
                ))
                ->toArray()
        );
    }

    /**
     * @return array<int, Exercise1RMProgressPoint>
     */
    public function getExercise1RMProgress(User $user, int $exerciseId, int $days = 90): array
    {
        $version = Cache::get("stats.1rm_version.{$user->id}", '1');
        $version = is_scalar($version) ? (string) $version : '1';

        return Cache::remember(
            "stats.1rm.{$user->id}.{$exerciseId}.{$days}.v{$version}",
            now()->addMinutes(30),
            fn (): array => Set::query()
                ->toBase()
                ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
                ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
                ->where('workouts.user_id', $user->id)
                ->where('workout_lines.exercise_id', $exerciseId)
                ->where('workouts.started_at', '>=', now()->subDays($days))
                ->selectRaw('workouts.started_at, MAX(sets.weight * (1 + sets.reps / 30.0)) as epley_1rm')
                ->groupBy('workouts.started_at')
                ->orderBy('workouts.started_at')
                ->get()
                ->map(function (\stdClass $set): ?Exercise1RMProgressPoint {
                    // ⚡ Bolt Optimization: Use native PHP timestamp math instead of Carbon objects
                    // to eliminate O(N) object instantiation overhead inside the loop.
                    $timestamp = strtotime((string) $set->started_at);

                    if ($timestamp === false) {
                        return null;
                    }

                    return new Exercise1RMProgressPoint(
                        date('d/m', $timestamp),
                        date('Y-m-d', $timestamp),
                        (float) $set->epley_1rm,
                    );
                })
                ->filter()
                ->values()
                ->toArray()
        );
    }
}
