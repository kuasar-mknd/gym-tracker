<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workout;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service for calculating and retrieving user workout statistics.
 *
 * This service handles heavy aggregations and calculations for:
 * - Volume trends over time
 * - Muscle distribution analysis
 * - Estimated 1RM (One Rep Max) progression
 * - Period-over-period comparisons
 *
 * It utilizes caching (via Redis/Cache facade) to optimize performance for expensive database queries.
 */
class StatsService
{
    /**
     * Get volume trend (total weight lifted) per workout over time.
     *
     * Retrieves a list of workouts within the specified period and calculates
     * the total volume (weight * reps) for each.
     *
     * @param  User  $user  The user to retrieve stats for.
     * @param  int  $days  Number of days to look back (default: 30).
     * @return array<int, array{
     *     date: string,
     *     full_date: string,
     *     name: string,
     *     volume: float
     * }> List of workout volume data points.
     *
     * @example
     * [
     *   ['date' => '01/05', 'full_date' => '2023-05-01', 'name' => 'Leg Day', 'volume' => 12500],
     *   ...
     * ]
     */
    public function getVolumeTrend(User $user, int $days = 30): array
    {
        // Note: Cache tags removed - file driver doesn't support tagging
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.volume_trend.{$user->id}.{$days}",
            now()->addMinutes(30),
            function () use ($user, $days) {
                $results = DB::table('workouts')
                    ->leftJoin('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
                    ->leftJoin('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
                    ->where('workouts.user_id', $user->id)
                    ->where('workouts.started_at', '>=', now()->subDays($days))
                    ->select(
                        'workouts.id',
                        'workouts.started_at',
                        'workouts.name',
                        // SECURITY: Static DB::raw - safe. DO NOT concatenate user input here.
                        DB::raw('COALESCE(SUM(sets.weight * sets.reps), 0) as volume')
                    )
                    ->groupBy('workouts.id', 'workouts.started_at', 'workouts.name')
                    ->orderBy('workouts.started_at')
                    ->get();

                return $results->map(function ($row) {
                    return [
                        'date' => Carbon::parse($row->started_at)->format('d/m'),
                        'full_date' => Carbon::parse($row->started_at)->format('Y-m-d'),
                        'name' => $row->name,
                        'volume' => (float) $row->volume,
                    ];
                })->values()->toArray();
            }
        );
    }

    /**
     * Get muscle group distribution based on volume (weight * reps).
     *
     * Aggregates the total volume lifted per exercise category (muscle group).
     * Uses a direct database query for performance optimization.
     *
     * @param  User  $user  The user to retrieve stats for.
     * @param  int  $days  Number of days to look back (default: 30).
     * @return array<int, object{
     *     category: string,
     *     volume: string|float
     * }> Array of objects containing category names and aggregated volume.
     *
     * @example
     * [
     *   (object) ['category' => 'Pectoraux', 'volume' => 5000],
     *   (object) ['category' => 'Dos', 'volume' => 4500],
     * ]
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
                    ->selectRaw('exercises.category, SUM(sets.weight * sets.reps) as volume')
                    ->groupBy('exercises.category')
                    ->get();

                return $results->toArray();
            }
        );
    }

    /**
     * Get Estimated 1RM evolution for a specific exercise using Epley formula.
     *
     * Calculates the estimated One Rep Max for each workout session where the exercise was performed.
     * Formula: Weight * (1 + Reps / 30)
     * Takes the maximum estimated 1RM achieved in a single set for that day.
     *
     * @param  User  $user  The user to retrieve stats for.
     * @param  int  $exerciseId  The ID of the exercise to analyze.
     * @param  int  $days  Number of days to look back (default: 90).
     * @return array<int, array{
     *     date: string,
     *     full_date: string,
     *     one_rep_max: float
     * }> Timeline of 1RM progress.
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
                    ->selectRaw('workouts.started_at, MAX(sets.weight * (1 + sets.reps / 30.0)) as epley_1rm')
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
     *
     * Calculates the total volume lifted in the current month versus the previous month
     * and returns the percentage difference.
     *
     * @param  User  $user  The user to retrieve stats for.
     * @return array{
     *     current_month_volume: float,
     *     previous_month_volume: float,
     *     difference: float,
     *     percentage: float
     * } Comparison data including volume totals and percentage change.
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
            // SECURITY: Static DB::raw - safe. DO NOT concatenate user input here.
            ->sum(DB::raw('sets.weight * sets.reps'));

        $previousVolume = DB::table('sets')
            ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
            ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
            ->where('workouts.user_id', $user->id)
            ->whereBetween('workouts.started_at', [$previousMonthStart, $previousMonthEnd])
            // SECURITY: Static DB::raw - safe. DO NOT concatenate user input here.
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
