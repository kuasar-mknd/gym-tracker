<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ExerciseStatsService
{
    /**
     * @return array<int, array{category: string, volume: float}>
     */
    public function getMuscleDistribution(User $user, int $days = 30): array
    {
        return Cache::remember(
            "stats.muscle_dist.{$user->id}.{$days}",
            now()->addMinutes(30),
            fn (): array => DB::table('sets')
                ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
                ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
                ->join('exercises', 'workout_lines.exercise_id', '=', 'exercises.id')
                ->where('workouts.user_id', $user->id)
                ->where('workouts.started_at', '>=', now()->subDays($days))
                ->selectRaw('exercises.category, SUM(sets.weight * sets.reps) as volume')
                ->groupBy('exercises.category')
                ->get()
                ->map(fn (\stdClass $row): array => [
                    'category' => (string) ($row->category ?? 'Unknown'),
                    'volume' => (float) ($row->volume ?? 0.0),
                ])
                ->toArray()
        );
    }

    /**
     * @return array<int, array{date: string, full_date: string, one_rep_max: float}>
     */
    public function getExercise1RMProgress(User $user, int $exerciseId, int $days = 90): array
    {
        $version = Cache::get("stats.1rm_version.{$user->id}", '1');
        $version = is_scalar($version) ? (string) $version : '1';

        return Cache::remember(
            "stats.1rm.{$user->id}.{$exerciseId}.{$days}.v{$version}",
            now()->addMinutes(30),
            fn (): array => DB::table('sets')
                ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
                ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
                ->where('workouts.user_id', $user->id)
                ->where('workout_lines.exercise_id', $exerciseId)
                ->where('workouts.started_at', '>=', now()->subDays($days))
                ->selectRaw('workouts.started_at, MAX(sets.weight * (1 + sets.reps / 30.0)) as epley_1rm')
                ->groupBy('workouts.started_at')
                ->orderBy('workouts.started_at')
                ->get()
                ->map(fn (\stdClass $set): array => [
                    'date' => Carbon::parse($set->started_at)->format('d/m'),
                    'full_date' => Carbon::parse($set->started_at)->format('Y-m-d'),
                    'one_rep_max' => (float) $set->epley_1rm,
                ])
                ->toArray()
        );
    }
}
