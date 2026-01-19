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
     * Get daily volume trend for the last X days.
     *
     * Returns an array of volume for each day, ensuring zero values for days without workouts.
     *
     * @return array<int, array{date: string, day_name: string, volume: float}>
     */
    public function getDailyVolumeTrend(User $user, int $days = 7): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.daily_volume.{$user->id}.{$days}",
            now()->addMinutes(30),
            function () use ($user, $days) {
                $end = now()->endOfDay();
                $start = now()->subDays($days - 1)->startOfDay();

                $results = DB::table('workouts')
                    ->leftJoin('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
                    ->leftJoin('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
                    ->where('workouts.user_id', $user->id)
                    ->whereBetween('workouts.started_at', [$start, $end])
                    ->select(
                        DB::raw('DATE(workouts.started_at) as date'),
                        DB::raw('COALESCE(SUM(sets.weight * sets.reps), 0) as volume')
                    )
                    ->groupBy('date')
                    ->get()
                    ->pluck('volume', 'date');

                $data = [];
                for ($i = 0; $i < $days; $i++) {
                    $date = $start->copy()->addDays($i);
                    $dateString = $date->format('Y-m-d');
                    $data[] = [
                        'date' => $date->format('d/m'),
                        'day_name' => $date->translatedFormat('D'),
                        'volume' => (float) ($results[$dateString] ?? 0),
                    ];
                }

                return $data;
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
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.monthly_volume_comparison.{$user->id}",
            now()->addMinutes(30),
            function () use ($user) {
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
        );
    }

    /**
     * Get weight history for the last X days.
     *
     * @return array<int, array{date: string, weight: float}>
     */
    public function getWeightHistory(User $user, int $days = 90): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.weight_history.{$user->id}.{$days}",
            now()->addMinutes(30),
            function () use ($user, $days) {
                $measurements = $user->bodyMeasurements()
                    ->where('measured_at', '>=', now()->subDays($days))
                    ->orderBy('measured_at', 'asc')
                    ->get();

                return $measurements->map(function ($m) {
                    return [
                        'date' => Carbon::parse($m->measured_at)->format('d/m'),
                        'full_date' => Carbon::parse($m->measured_at)->format('Y-m-d'),
                        'weight' => (float) $m->weight,
                    ];
                })->toArray();
            }
        );
    }

    /**
     * Get latest body metrics and weight change.
     *
     * @return array{
     *     latest_weight: float|null,
     *     weight_change: float,
     *     latest_body_fat: float|null
     * }
     */
    public function getLatestBodyMetrics(User $user): array
    {
        $latest = $user->bodyMeasurements()->latest('measured_at')->first();
        $previous = $user->bodyMeasurements()
            ->where('id', '!=', $latest?->id)
            ->latest('measured_at')
            ->first();

        $weightChange = 0;
        if ($latest && $previous) {
            $weightChange = round($latest->weight - $previous->weight, 1);
        }

        return [
            'latest_weight' => $latest?->weight ? (float) $latest->weight : null,
            'weight_change' => (float) $weightChange,
            'latest_body_fat' => $latest?->body_fat ? (float) $latest->body_fat : null,
        ];
    }

    /**
    /**
     * Get body fat history for the last X days.
     *
     * @return array<int, array{date: string, body_fat: float}>
     */
    public function getBodyFatHistory(User $user, int $days = 90): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.body_fat_history.{$user->id}.{$days}",
            now()->addMinutes(30),
            function () use ($user, $days) {
                $measurements = $user->bodyMeasurements()
                    ->where('measured_at', '>=', now()->subDays($days))
                    ->whereNotNull('body_fat')
                    ->orderBy('measured_at', 'asc')
                    ->get();

                return $measurements->map(function ($m) {
                    return [
                        'date' => Carbon::parse($m->measured_at)->format('d/m'),
                        'full_date' => Carbon::parse($m->measured_at)->format('Y-m-d'),
                        'body_fat' => (float) $m->body_fat,
                    ];
                })->toArray();
            }
        );
    }

    /**
     * Get volume trend for the current week (Monday to Sunday).
     *
     * Returns an array of objects for each day of the current week,
     * with volume summed up. Fills missing days with 0.
     *
     * @param  User  $user  The user to retrieve stats for.
     * @return array<int, array{
     *     date: string,
     *     day_label: string,
     *     volume: float
     * }>
     */
    public function getWeeklyVolumeTrend(User $user): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.weekly_volume.{$user->id}",
            now()->addMinutes(10),
            function () use ($user) {
                $startOfWeek = now()->startOfWeek();
                $endOfWeek = now()->endOfWeek();

                // Get raw data from DB
                $workouts = DB::table('workouts')
                    ->leftJoin('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
                    ->leftJoin('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
                    ->where('workouts.user_id', $user->id)
                    ->whereBetween('workouts.started_at', [$startOfWeek, $endOfWeek])
                    ->select(
                        DB::raw('DATE(workouts.started_at) as date'),
                        DB::raw('COALESCE(SUM(sets.weight * sets.reps), 0) as volume')
                    )
                    ->groupBy(DB::raw('DATE(workouts.started_at)'))
                    ->get()
                    ->keyBy('date');

                // Fill Mon-Sun
                $trend = [];
                $current = $startOfWeek->copy();
                $labels = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

                for ($i = 0; $i < 7; $i++) {
                    $dateStr = $current->format('Y-m-d');
                    $volume = isset($workouts[$dateStr]) ? (float) $workouts[$dateStr]->volume : 0.0;

                    $trend[] = [
                        'date' => $dateStr,
                        'day_label' => $labels[$i],
                        'volume' => $volume,
                    ];

                    $current->addDay();
                }

                return $trend;
            }
        );
    }

    /**
     * Get volume comparison between current week and previous week.
     *
     * @param  User  $user  The user to retrieve stats for.
     * @return array{
     *     current_week_volume: float,
     *     previous_week_volume: float,
     *     difference: float,
     *     percentage: float
     * }
     */
    public function getWeeklyVolumeComparison(User $user): array
    {
        $currentStart = now()->startOfWeek();
        $previousStart = now()->subWeek()->startOfWeek();
        $previousEnd = now()->subWeek()->endOfWeek();

        $currentVolume = DB::table('sets')
            ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
            ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
            ->where('workouts.user_id', $user->id)
            ->where('workouts.started_at', '>=', $currentStart)
            ->sum(DB::raw('sets.weight * sets.reps'));

        $previousVolume = DB::table('sets')
            ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
            ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
            ->where('workouts.user_id', $user->id)
            ->whereBetween('workouts.started_at', [$previousStart, $previousEnd])
            ->sum(DB::raw('sets.weight * sets.reps'));

        $diff = $currentVolume - $previousVolume;
        $percentage = $previousVolume > 0 ? ($diff / $previousVolume) * 100 : ($currentVolume > 0 ? 100 : 0);

        return [
            'current_week_volume' => (float) $currentVolume,
            'previous_week_volume' => (float) $previousVolume,
            'difference' => (float) $diff,
            'percentage' => (float) round($percentage, 1),
        ];
    }

    /**
     * Get workout duration history.
     *
     * @return array<int, array{date: string, duration: int, name: string}>
     */
    public function getDurationHistory(User $user, int $limit = 20): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.duration_history.{$user->id}.{$limit}",
            now()->addMinutes(30),
            function () use ($user, $limit) {
                return Workout::select('name', 'started_at', 'ended_at')
                    ->where('user_id', $user->id)
                    ->whereNotNull('ended_at')
                    ->latest('started_at')
                    ->take($limit)
                    ->get()
                    ->map(function ($workout) {
                        return [
                            'date' => $workout->started_at->format('d/m'),
                            'duration' => $workout->ended_at->diffInMinutes($workout->started_at),
                            'name' => $workout->name,
                        ];
                    })
                    ->reverse()
                    ->values()
                    ->toArray();
            }
        );
    }

    /**
     * Get volume history per workout.
     *
     * @return array<int, array{date: string, volume: float, name: string}>
     */
    public function getVolumeHistory(User $user, int $limit = 20): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.volume_history.{$user->id}.{$limit}",
            now()->addMinutes(30),
            function () use ($user, $limit) {
                return Workout::with(['workoutLines.sets'])
                    ->where('user_id', $user->id)
                    ->whereNotNull('ended_at')
                    ->latest('started_at')
                    ->take($limit)
                    ->get()
                    ->map(function ($workout) {
                        $volume = $workout->workoutLines->reduce(function ($carry, $line) {
                            return $carry + $line->sets->reduce(function ($carrySet, $set) {
                                return $carrySet + ($set->weight * $set->reps);
                            }, 0);
                        }, 0);

                        return [
                            'date' => $workout->started_at->format('d/m'),
                            'volume' => $volume,
                            'name' => $workout->name,
                        ];
                    })
                    ->reverse()
                    ->values()
                    ->toArray();
            }
        );
    }

    /**
     * Clear all cached statistics for a given user.
     */
    public function clearUserStatsCache(User $user): void
    {
        // Clear all possible period variations
        $periods = [7, 30, 90, 365];
        foreach ($periods as $days) {
            \Illuminate\Support\Facades\Cache::forget("stats.volume_trend.{$user->id}.{$days}");
            \Illuminate\Support\Facades\Cache::forget("stats.daily_volume.{$user->id}.{$days}");
            \Illuminate\Support\Facades\Cache::forget("stats.muscle_dist.{$user->id}.{$days}");
            \Illuminate\Support\Facades\Cache::forget("stats.weight_history.{$user->id}.{$days}");
            \Illuminate\Support\Facades\Cache::forget("stats.body_fat_history.{$user->id}.{$days}");
        }

        // Clear dashboard-specific cache
        \Illuminate\Support\Facades\Cache::forget("dashboard_data_{$user->id}");

        // Clear duration and volume history caches
        \Illuminate\Support\Facades\Cache::forget("stats.duration_history.{$user->id}.20");
        \Illuminate\Support\Facades\Cache::forget("stats.duration_history.{$user->id}.30");
        \Illuminate\Support\Facades\Cache::forget("stats.volume_history.{$user->id}.20");
        \Illuminate\Support\Facades\Cache::forget("stats.volume_history.{$user->id}.30");

        // Note: Individual exercise 1RM progress is not cleared here as it's exercise-specific
    }
}
