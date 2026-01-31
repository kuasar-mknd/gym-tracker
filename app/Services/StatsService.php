<?php

declare(strict_types=1);

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
final class StatsService
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
     *   ...
     * ]
     */
    public function getVolumeTrend(User $user, int $days = 30): array
    {
        // Note: Cache tags removed - file driver doesn't support tagging
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.volume_trend.{$user->id}.{$days}",
            now()->addMinutes(30),
            function () use ($user, $days): array {
                $results = $this->fetchVolumeTrendData($user, $days);

                return $results->map(fn (\stdClass $row): array => $this->formatVolumeTrendItem($row))
                    ->values()
                    ->toArray();
            }
        );
    }

    /**
     * Get daily volume trend for the last X days.
     *
     * @return array<int, array{date: string, day_name: string, volume: float}>
     */
    public function getDailyVolumeTrend(User $user, int $days = 7): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.daily_volume.{$user->id}.{$days}",
            now()->addMinutes(30),
            function () use ($user, $days): array {
                $start = now()->subDays($days - 1)->startOfDay();

                return $this->fillDailyTrend($start, $days, $this->fetchDailyVolumeData($user, $start));
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
     * @return array<int, \stdClass>
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
            fn (): array => $this->fetchMuscleDistributionData($user, $days)->toArray()
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
            fn (): array => $this->fetchExercise1RMData($user, $exerciseId, $days)
                ->map(fn (\stdClass $set): array => $this->formatExercise1RMItem($set))
                ->toArray()
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
        /** @var array{current_volume: float, previous_volume: float, difference: float, percentage: float} $comparison */
        $comparison = \Illuminate\Support\Facades\Cache::remember(
            "stats.monthly_volume_comparison.{$user->id}",
            now()->addMinutes(30),
            fn (): array => $this->calculatePeriodComparison(
                $user,
                now()->startOfMonth(),
                now()->subMonth()->startOfMonth(),
                now()->subMonth()->endOfMonth()
            )
        );

        return [
            'current_month_volume' => $comparison['current_volume'],
            'previous_month_volume' => $comparison['previous_volume'],
            'difference' => $comparison['difference'],
            'percentage' => $comparison['percentage'],
        ];
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
            fn (): array => $this->fetchWeightHistoryData($user, $days)
                ->map(fn (\App\Models\BodyMeasurement $m): array => $this->formatWeightHistoryItem($m))
                ->toArray()
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
        /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\BodyMeasurement> $measurements */
        $measurements = $user->bodyMeasurements()
            ->latest('measured_at')
            ->take(2)
            ->get();

        /** @var \App\Models\BodyMeasurement|null $latest */
        $latest = $measurements->first();
        /** @var \App\Models\BodyMeasurement|null $previous */
        $previous = $measurements->skip(1)->first();

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
     * Get body fat history for the last X days.
     *
     * @return array<int, array{date: string, body_fat: float}>
     */
    public function getBodyFatHistory(User $user, int $days = 90): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.body_fat_history.{$user->id}.{$days}",
            now()->addMinutes(30),
            function () use ($user, $days): array {
                /** @var array<int, array{date: string, body_fat: float}> $results */
                $results = $this->fetchBodyFatHistoryData($user, $days)
                    ->map(fn (\App\Models\BodyMeasurement $m): array => $this->formatBodyFatHistoryItem($m))
                    ->toArray();

                return $results;
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
            function () use ($user): array {
                $startOfWeek = now()->startOfWeek();
                $endOfWeek = now()->endOfWeek();

                $workouts = $this->fetchWeeklyVolumeData($user, $startOfWeek, $endOfWeek);

                return $this->fillWeeklyTrend($startOfWeek, $workouts);
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
        $comparison = $this->calculatePeriodComparison(
            $user,
            now()->startOfWeek(),
            now()->subWeek()->startOfWeek(),
            now()->subWeek()->endOfWeek()
        );

        return [
            'current_week_volume' => $comparison['current_volume'],
            'previous_week_volume' => $comparison['previous_volume'],
            'difference' => $comparison['difference'],
            'percentage' => $comparison['percentage'],
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
            function () use ($user, $limit): array {
                /** @var array<int, array{date: string, duration: int, name: string}> $results */
                $results = Workout::select(['name', 'started_at', 'ended_at'])
                    ->where('user_id', $user->id)
                    ->whereNotNull('ended_at')
                    ->latest('started_at')
                    ->take($limit)
                    ->get()
                    ->map(fn (\App\Models\Workout $workout): array => $this->formatDurationHistoryItem($workout))
                    ->reverse()
                    ->values()
                    ->toArray();

                return $results;
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
            fn (): array => DB::table('workouts')
                ->leftJoin('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
                ->leftJoin('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
                ->where('workouts.user_id', $user->id)
                ->whereNotNull('workouts.ended_at')
                ->select(
                    'workouts.id',
                    'workouts.started_at',
                    'workouts.name',
                    // SECURITY: Static DB::raw - safe. DO NOT concatenate user input here.
                    DB::raw('COALESCE(SUM(sets.weight * sets.reps), 0) as volume')
                )
                ->groupBy('workouts.id', 'workouts.started_at', 'workouts.name')
                ->orderByDesc('workouts.started_at')
                ->limit($limit)
                ->get()
                ->map(fn (object $row): array => [
                    'date' => Carbon::parse($row->started_at)->format('d/m'),
                    'volume' => (float) $row->volume,
                    'name' => (string) $row->name,
                ])
                ->reverse()
                ->values()
                ->toArray()
        );
    }

    /**
     * Get workout duration distribution (buckets) for the last X days.
     *
     * @return array<int, array{label: string, count: int}>
     */
    public function getDurationDistribution(User $user, int $days = 90): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.duration_distribution.{$user->id}.{$days}",
            now()->addMinutes(30),
            function () use ($user, $days): array {
                $workouts = Workout::select(['started_at', 'ended_at'])
                    ->where('user_id', $user->id)
                    ->whereNotNull('ended_at')
                    ->where('started_at', '>=', now()->subDays($days))
                    ->get();

                $buckets = [
                    '< 30 min' => 0,
                    '30-60 min' => 0,
                    '60-90 min' => 0,
                    '90+ min' => 0,
                ];

                foreach ($workouts as $workout) {
                    if (! $workout->ended_at) {
                        continue;
                    }

                    $minutes = abs($workout->ended_at->diffInMinutes($workout->started_at));

                    if ($minutes < 30) {
                        $buckets['< 30 min']++;
                    } elseif ($minutes < 60) {
                        $buckets['30-60 min']++;
                    } elseif ($minutes < 90) {
                        $buckets['60-90 min']++;
                    } else {
                        $buckets['90+ min']++;
                    }
                }

                $result = [];
                foreach ($buckets as $label => $count) {
                    $result[] = ['label' => $label, 'count' => $count];
                }

                return $result;
            }
        );
    }

    /**
     * Get monthly volume history for the last X months.
     *
     * @return array<int, array{month: string, volume: float}>
     */
    public function getMonthlyVolumeHistory(User $user, int $months = 6): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.monthly_volume_history.{$user->id}.{$months}",
            now()->addMinutes(30),
            function () use ($user, $months): array {
                $data = $this->fetchMonthlyVolumeHistoryData($user, $months);

                // Group by month YYYY-MM
                $grouped = $data->groupBy(function ($row) {
                    return Carbon::parse($row->started_at)->format('Y-m');
                });

                // Fill last X months (including current)
                $result = [];
                for ($i = $months - 1; $i >= 0; $i--) {
                    $date = now()->subMonths($i);
                    $key = $date->format('Y-m');
                    $monthLabel = $date->translatedFormat('M'); // Jan, Fév... (depends on locale)

                    $volume = 0.0;
                    /** @var \Illuminate\Support\Collection<int, \stdClass>|null $monthData */
                    $monthData = $grouped->get($key);

                    if ($monthData) {
                        $volume = (float) $monthData->sum('volume');
                    }

                    $result[] = [
                        'month' => $monthLabel,
                        'volume' => $volume,
                    ];
                }

                return $result;
            }
        );
    }

    public function clearUserStatsCache(User $user): void
    {
        $this->clearWorkoutRelatedStats($user);
        $this->clearBodyMeasurementStats($user);
    }

    /**
     * Clear only workout-related statistics cache.
     * This avoids clearing weight/body fat history when only workout data changes.
     */
    public function clearWorkoutRelatedStats(User $user): void
    {
        $periods = [7, 30, 90, 365];
        foreach ($periods as $days) {
            \Illuminate\Support\Facades\Cache::forget("stats.volume_trend.{$user->id}.{$days}");
            \Illuminate\Support\Facades\Cache::forget("stats.daily_volume.{$user->id}.{$days}");
            \Illuminate\Support\Facades\Cache::forget("stats.muscle_dist.{$user->id}.{$days}");
        }

        // Clear dashboard-specific cache (contains both workout and weight data)
        \Illuminate\Support\Facades\Cache::forget("dashboard_data_{$user->id}");

        // Clear duration and volume history caches
        \Illuminate\Support\Facades\Cache::forget("stats.duration_history.{$user->id}.20");
        \Illuminate\Support\Facades\Cache::forget("stats.duration_history.{$user->id}.30");
        \Illuminate\Support\Facades\Cache::forget("stats.volume_history.{$user->id}.20");
        \Illuminate\Support\Facades\Cache::forget("stats.volume_history.{$user->id}.30");

        // Clear weekly volume and monthly comparison (previously missed)
        \Illuminate\Support\Facades\Cache::forget("stats.weekly_volume.{$user->id}");
        \Illuminate\Support\Facades\Cache::forget("stats.monthly_volume_comparison.{$user->id}");
        \Illuminate\Support\Facades\Cache::forget("stats.duration_distribution.{$user->id}.90");
        \Illuminate\Support\Facades\Cache::forget("stats.monthly_volume_history.{$user->id}.6");
    }

    /**
     * Clear only body measurement statistics cache.
     * This avoids clearing workout history when only weight/body fat data changes.
     */
    public function clearBodyMeasurementStats(User $user): void
    {
        $periods = [7, 30, 90, 365];
        foreach ($periods as $days) {
            \Illuminate\Support\Facades\Cache::forget("stats.weight_history.{$user->id}.{$days}");
            \Illuminate\Support\Facades\Cache::forget("stats.body_fat_history.{$user->id}.{$days}");
        }

        // Clear dashboard-specific cache (contains both workout and weight data)
        \Illuminate\Support\Facades\Cache::forget("dashboard_data_{$user->id}");
    }

    protected function getPeriodVolume(User $user, Carbon $start, ?Carbon $end = null): float
    {
        $query = DB::table('sets')
            ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
            ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
            ->where('workouts.user_id', $user->id);

        if ($end) {
            $query->whereBetween('workouts.started_at', [$start, $end]);
        } else {
            $query->where('workouts.started_at', '>=', $start);
        }

        // SECURITY: Static DB::raw - safe. DO NOT concatenate user input here.
        return (float) $query->sum(DB::raw('sets.weight * sets.reps'));
    }

    /**
     * Fetch weekly volume data from DB.
     *
     * @return \Illuminate\Support\Collection<string, \stdClass>
     */
    protected function fetchWeeklyVolumeData(User $user, Carbon $startOfWeek, Carbon $endOfWeek): \Illuminate\Support\Collection
    {
        return DB::table('workouts')
            ->leftJoin('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->leftJoin('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->where('workouts.user_id', $user->id)
            ->whereBetween('workouts.started_at', [$startOfWeek, $endOfWeek])
            ->select(
                // SECURITY: Static DB::raw - safe. DO NOT concatenate user input here.
                DB::raw('DATE(workouts.started_at) as date'),
                DB::raw('COALESCE(SUM(sets.weight * sets.reps), 0) as volume')
            )
            ->groupBy(DB::raw('DATE(workouts.started_at)'))
            ->get()
            ->keyBy('date');
    }

    /**
     * Fill missing days with zero volume.
     *
     * @param  \Illuminate\Support\Collection<string, float>  $results
     * @return array<int, array{date: string, day_name: string, volume: float}>
     */
    protected function fillDailyTrend(Carbon $start, int $days, \Illuminate\Support\Collection $results): array
    {
        $data = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);
            $dateString = $date->format('Y-m-d');
            /** @var float $volume */
            $volume = $results[$dateString] ?? 0.0;
            $data[] = [
                'date' => $date->format('d/m'),
                'day_name' => $date->translatedFormat('D'),
                'volume' => (float) $volume,
            ];
        }

        return $data;
    }

    /**
     * Fill missing days in weekly trend with zero volume.
     *
     * @param  \Illuminate\Support\Collection<string, \stdClass>  $workouts
     * @return array<int, array{date: string, day_label: string, volume: float}>
     */
    protected function fillWeeklyTrend(Carbon $startOfWeek, \Illuminate\Support\Collection $workouts): array
    {
        $trend = [];
        $current = $startOfWeek->copy();
        $labels = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

        for ($i = 0; $i < 7; $i++) {
            $dateStr = $current->format('Y-m-d');
            $volume = 0.0;
            if (isset($workouts[$dateStr])) {
                /** @var object{volume: int|float} $workoutData */
                $workoutData = $workouts[$dateStr];
                $volume = (float) $workoutData->volume;
            }

            $trend[] = [
                'date' => $dateStr,
                'day_label' => $labels[$i],
                'volume' => $volume,
            ];
            $current->addDay();
        }

        return $trend;
    }

    /**
     * Format row for volume trend.
     *
     * @return array{date: string, full_date: string, name: string, volume: float}
     */
    protected function formatVolumeTrendItem(\stdClass $row): array
    {
        return [
            'date' => Carbon::parse($row->started_at)->format('d/m'),
            'full_date' => Carbon::parse($row->started_at)->format('Y-m-d'),
            'name' => $row->name,
            'volume' => (float) $row->volume,
        ];
    }

    /**
     * Format set for 1RM item.
     *
     * @return array{date: string, full_date: string, one_rep_max: float}
     */
    protected function formatExercise1RMItem(\stdClass $set): array
    {
        return [
            'date' => Carbon::parse($set->started_at)->format('d/m'),
            'full_date' => Carbon::parse($set->started_at)->format('Y-m-d'),
            'one_rep_max' => round((float) $set->epley_1rm, 2),
        ];
    }

    /**
     * Format workout for duration history.
     *
     * @return array{date: string, duration: int, name: string}
     */
    protected function formatDurationHistoryItem(Workout $workout): array
    {
        return [
            'date' => $workout->started_at->format('d/m'),
            'duration' => (int) ($workout->ended_at ? abs($workout->ended_at->diffInMinutes($workout->started_at)) : 0),
            'name' => (string) $workout->name,
        ];
    }

    /**
     * Fetch volume trend data from DB.
     *
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    protected function fetchVolumeTrendData(User $user, int $days): \Illuminate\Support\Collection
    {
        return DB::table('workouts')
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
    }

    /**
     * Fetch daily volume data from DB.
     *
     * @return \Illuminate\Support\Collection<string, float>
     */
    protected function fetchDailyVolumeData(User $user, Carbon $start): \Illuminate\Support\Collection
    {
        return DB::table('workouts')
            ->leftJoin('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->leftJoin('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->where('workouts.user_id', $user->id)
            ->whereBetween('workouts.started_at', [$start, now()->endOfDay()])
            ->select(
                DB::raw('DATE(workouts.started_at) as date'),
                DB::raw('COALESCE(SUM(sets.weight * sets.reps), 0) as volume')
            )
            ->groupBy('date')
            ->pluck('volume', 'date')
            ->map(function (mixed $value): float {
                return is_numeric($value) ? (float) $value : 0.0;
            });
    }

    /**
     * Fetch monthly volume history data from DB.
     *
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    protected function fetchMonthlyVolumeHistoryData(User $user, int $months): \Illuminate\Support\Collection
    {
        $startDate = now()->subMonths($months - 1)->startOfMonth();

        // Fetch raw data per workout to aggregate in PHP (Database agnostic)
        return DB::table('workouts')
            ->leftJoin('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->leftJoin('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->where('workouts.user_id', $user->id)
            ->where('workouts.started_at', '>=', $startDate)
            ->select(
                'workouts.started_at',
                DB::raw('COALESCE(SUM(sets.weight * sets.reps), 0) as volume')
            )
            ->groupBy('workouts.id', 'workouts.started_at')
            ->get();
    }

    /**
     * Fetch muscle distribution data from DB.
     *
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    protected function fetchMuscleDistributionData(User $user, int $days): \Illuminate\Support\Collection
    {
        return DB::table('sets')
            ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
            ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
            ->join('exercises', 'workout_lines.exercise_id', '=', 'exercises.id')
            ->where('workouts.user_id', $user->id)
            ->where('workouts.started_at', '>=', now()->subDays($days))
            // SECURITY: Static selectRaw - safe. DO NOT concatenate user input here.
            ->selectRaw('exercises.category, SUM(sets.weight * sets.reps) as volume')
            ->groupBy('exercises.category')
            ->get();
    }

    /**
     * Fetch exercise 1RM progress from DB.
     *
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    protected function fetchExercise1RMData(User $user, int $exerciseId, int $days): \Illuminate\Support\Collection
    {
        return DB::table('sets')
            ->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')
            ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
            ->where('workouts.user_id', $user->id)
            ->where('workout_lines.exercise_id', $exerciseId)
            ->where('workouts.started_at', '>=', now()->subDays($days))
            // SECURITY: Static selectRaw - safe. DO NOT concatenate user input here.
            ->selectRaw('workouts.started_at, MAX(sets.weight * (1 + sets.reps / 30.0)) as epley_1rm')
            ->groupBy('workouts.started_at')
            ->orderBy('workouts.started_at')
            ->get();
    }

    /**
     * Fetch weight history.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\BodyMeasurement>
     */
    protected function fetchWeightHistoryData(User $user, int $days): \Illuminate\Database\Eloquent\Collection
    {
        return $user->bodyMeasurements()
            ->where('measured_at', '>=', now()->subDays($days))
            ->orderBy('measured_at', 'asc')
            ->get();
    }

    /**
     * Fetch body fat history.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\BodyMeasurement>
     */
    protected function fetchBodyFatHistoryData(User $user, int $days): \Illuminate\Database\Eloquent\Collection
    {
        return $user->bodyMeasurements()
            ->where('measured_at', '>=', now()->subDays($days))
            ->whereNotNull('body_fat')
            ->orderBy('measured_at', 'asc')
            ->get();
    }

    /**
     * Format weight history item.
     *
     * @return array{date: string, full_date: string, weight: float}
     */
    protected function formatWeightHistoryItem(\App\Models\BodyMeasurement $m): array
    {
        return [
            'date' => Carbon::parse($m->measured_at)->format('d/m'),
            'full_date' => Carbon::parse($m->measured_at)->format('Y-m-d'),
            'weight' => (float) $m->weight,
        ];
    }

    /**
     * Format body fat history item.
     *
     * @return array{date: string, full_date: string, body_fat: float}
     */
    protected function formatBodyFatHistoryItem(\App\Models\BodyMeasurement $m): array
    {
        return [
            'date' => Carbon::parse($m->measured_at)->format('d/m'),
            'full_date' => Carbon::parse($m->measured_at)->format('Y-m-d'),
            'body_fat' => (float) $m->body_fat,
        ];
    }

    /**
     * Calculate comparison between two periods.
     *
     * @return array{
     *     current_volume: float,
     *     previous_volume: float,
     *     difference: float,
     *     percentage: float
     * }
     */
    protected function calculatePeriodComparison(User $user, Carbon $currentStart, Carbon $prevStart, ?Carbon $prevEnd = null): array
    {
        $currentVolume = $this->getPeriodVolume($user, $currentStart);
        $previousVolume = $this->getPeriodVolume($user, $prevStart, $prevEnd);

        $diff = $currentVolume - $previousVolume;
        $percentage = $previousVolume > 0 ? $diff / $previousVolume * 100 : ($currentVolume > 0 ? 100 : 0);

        return [
            'current_volume' => $currentVolume,
            'previous_volume' => $previousVolume,
            'difference' => $diff,
            'percentage' => round($percentage, 1),
        ];
    }
}
