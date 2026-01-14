<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workout;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StatsService
{
    /**
     * Get volume trend (total weight lifted) per workout over time.
     */
    public function getVolumeTrend(User $user, int $days = 30): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.volume_trend.{$user->id}.{$days}",
            now()->addMinutes(30),
            function () use ($user, $days) {
                return Workout::query()
                    ->where('user_id', $user->id)
                    ->where('started_at', '>=', now()->subDays($days))
                    ->with(['workoutLines.sets'])
                    ->get()
                    ->map(function ($workout) {
                        $volume = $workout->workoutLines->sum(function ($line) {
                            return $line->sets->sum(function ($set) {
                                return $set->weight * $set->reps;
                            });
                        });

                        return [
                            'date' => $workout->started_at->format('d/m'),
                            'full_date' => $workout->started_at->format('Y-m-d'),
                            'name' => $workout->name,
                            'volume' => $volume,
                        ];
                    })
                    ->values()
                    ->toArray();
            }
        );
    }

    /**
     * Get muscle group distribution based on volume (weight * reps).
     */
    public function getMuscleDistribution(User $user, int $days = 30): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.muscle_dist.{$user->id}.{$days}",
            now()->addMinutes(30),
            function () use ($user, $days) {
                $results = DB::table('sets')
                    ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
                    ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
                    ->join('exercises', 'workout_lines.exercise_id', '=', 'exercises.id')
                    ->where('workouts.user_id', $user->id)
                    ->where('workouts.started_at', '>=', now()->subDays($days))
                    ->select('exercises.category', DB::raw('SUM(sets.weight * sets.reps) as volume'))
                    ->groupBy('exercises.category')
                    ->get();

                return $results->toArray();
            }
        );
    }

    /**
     * Get Estimated 1RM evolution for a specific exercise using Epley formula.
     */
    public function getExercise1RMProgress(User $user, int $exerciseId, int $days = 90): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.1rm.{$user->id}.{$exerciseId}.{$days}",
            now()->addMinutes(30),
            function () use ($user, $exerciseId, $days) {
                $sets = DB::table('sets')
                    ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
                    ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
                    ->where('workouts.user_id', $user->id)
                    ->where('workout_lines.exercise_id', $exerciseId)
                    ->where('workouts.started_at', '>=', now()->subDays($days))
                    ->select(
                        'workouts.started_at',
                        DB::raw('MAX(sets.weight * (1 + sets.reps / 30.0)) as epley_1rm')
                    )
                    ->groupBy('workouts.started_at')
                    ->orderBy('workouts.started_at')
                    ->get();

                return $sets->map(function ($set) {
                    return [
                        'date' => Carbon::parse($set->started_at)->format('d/m'),
                        'full_date' => Carbon::parse($set->started_at)->format('Y-m-d'),
                        'one_rep_max' => (float) round($set->epley_1rm, 2),
                    ];
                })->toArray();
            }
        );
    }

    /**
     * Get volume comparison between current month and previous month.
     */
    public function getMonthlyVolumeComparison(User $user): array
    {
        $currentMonthStart = now()->startOfMonth();
        $previousMonthStart = now()->subMonth()->startOfMonth();
        $previousMonthEnd = now()->subMonth()->endOfMonth();

        $currentVolume = DB::table('sets')
            ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
            ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
            ->where('workouts.user_id', $user->id)
            ->where('workouts.started_at', '>=', $currentMonthStart)
            ->sum(DB::raw('sets.weight * sets.reps'));

        $previousVolume = DB::table('sets')
            ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
            ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
            ->where('workouts.user_id', $user->id)
            ->whereBetween('workouts.started_at', [$previousMonthStart, $previousMonthEnd])
            ->sum(DB::raw('sets.weight * sets.reps'));

        $diff = $currentVolume - $previousVolume;
        $percentage = $previousVolume > 0 ? ($diff / $previousVolume) * 100 : ($currentVolume > 0 ? 100 : 0);

        return [
            'current_month_volume' => (float) $currentVolume,
            'previous_month_volume' => (float) $previousVolume,
            'difference' => (float) $diff,
            'percentage' => (float) round($percentage, 1),
        ];
    }
}
