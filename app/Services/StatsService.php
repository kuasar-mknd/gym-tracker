<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Workout;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Service for calculating and retrieving user workout statistics.
 */
final class StatsService
{
    /**
     * @return array<int, array{date: string, full_date: string, name: string, volume: float}>
     */
    public function getVolumeTrend(User $user, int $days = 30): array
    {
        return Cache::remember(
            "stats.volume_trend.{$user->id}.{$days}",
            now()->addMinutes(30),
            fn (): array => $this->fetchVolumeTrendData($user, $days)
                ->map(fn (object $row): array => $this->formatVolumeTrendItem($row))
                ->values()
                ->toArray()
        );
    }

    /**
     * @return array<int, array{date: string, day_name: string, volume: float}>
     */
    public function getDailyVolumeTrend(User $user, int $days = 7): array
    {
        $cacheKey = "stats.daily_volume.{$user->id}.{$days}";

        return Cache::remember(
            $cacheKey,
            now()->addMinutes(30),
            function () use ($user, $days): array {
                $start = now()->subDays($days - 1)->startOfDay();

                return $this->fillDailyTrend($start, $days, $this->fetchDailyVolumeData($user, $start));
            }
        );
    }

    /**
     * @return array<int, array{category: string, volume: float}>
     */
    public function getMuscleDistribution(User $user, int $days = 30): array
    {
        return Cache::remember(
            "stats.muscle_dist.{$user->id}.{$days}",
            now()->addMinutes(30),
            fn (): array => $this->fetchMuscleDistributionData($user, $days)
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
            fn (): array => $this->fetchExercise1RMData($user, $exerciseId, $days)
                ->map(fn (\stdClass $set): array => $this->formatExercise1RMItem($set))
                ->toArray()
        );
    }

    /**
     * @return array{current_month_volume: float, previous_month_volume: float, difference: float, percentage: float}
     */
    public function getMonthlyVolumeComparison(User $user): array
    {
        /** @var array{current_volume: float, previous_volume: float, difference: float, percentage: float} $comparison */
        $comparison = Cache::remember(
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
     * @return array<int, array{date: string, full_date: string, weight: float}>
     */
    public function getWeightHistory(User $user, int $days = 90): array
    {
        return Cache::remember(
            "stats.weight_history.{$user->id}.{$days}",
            now()->addMinutes(30),
            fn (): array => $this->fetchWeightHistoryData($user, $days)
                ->map(fn (object $m): array => $this->formatWeightHistoryItem($m))
                ->toArray()
        );
    }

    /**
     * @return array{latest_weight: float|string|null, weight_change: float, latest_body_fat: float|string|null}
     */
    public function getLatestBodyMetrics(User $user): array
    {
        return Cache::remember(
            "stats.latest_metrics.{$user->id}",
            now()->addMinutes(30),
            function () use ($user): array {
                // ⚡ Bolt: PERFORMANCE OPTIMIZATION
                // Replaced Eloquent relation with DB facade to completely bypass model hydration.
                $measurements = DB::table('body_measurements')
                    ->where('user_id', $user->id)
                    ->select(['weight', 'body_fat', 'measured_at'])
                    ->latest('measured_at')
                    ->take(2)
                    ->get();

                /** @var \stdClass|null $latest */
                $latest = $measurements->first();
                /** @var \stdClass|null $previous */
                $previous = $measurements->skip(1)->first();

                $latestWeight = $latest ? (float) $latest->weight : null;
                $previousWeight = $previous ? (float) $previous->weight : null;
                $weightChange = $latestWeight !== null && $previousWeight !== null ? round($latestWeight - $previousWeight, 1) : 0;

                return [
                    'latest_weight' => $latestWeight !== null ? number_format($latestWeight, 2, '.', '') : null,
                    'weight_change' => (float) $weightChange,
                    'latest_body_fat' => $latest ? number_format((float) $latest->body_fat, 2, '.', '') : null,
                ];
            }
        );
    }

    /**
     * @return array<int, array{date: string, full_date: string, body_fat: float}>
     */
    public function getBodyFatHistory(User $user, int $days = 90): array
    {
        return Cache::remember(
            "stats.body_fat_history.{$user->id}.{$days}",
            now()->addMinutes(30),
            fn (): array => $this->fetchBodyFatHistoryData($user, $days)
                ->map(fn (object $m): array => $this->formatBodyFatHistoryItem($m))
                ->toArray()
        );
    }

    /**
     * @return array<int, array{date: string, day_label: string, volume: float}>
     */
    public function getWeeklyVolumeTrend(User $user): array
    {
        $cacheKey = "stats.weekly_volume.{$user->id}";

        return Cache::remember(
            $cacheKey,
            now()->addMinutes(10),
            function () use ($user): array {
                $startOfWeek = now()->startOfWeek();
                $endOfWeek = now()->endOfWeek();

                return $this->fillWeeklyTrend($startOfWeek, $this->fetchWeeklyVolumeData($user, $startOfWeek, $endOfWeek));
            }
        );
    }

    /**
     * @return array{current_week_volume: float, previous_week_volume: float, difference: float, percentage: float}
     */
    public function getWeeklyVolumeComparison(User $user): array
    {
        $weekKey = now()->startOfWeek()->format('Y-W');

        /** @var array{current_volume: float, previous_volume: float, difference: float, percentage: float} $comparison */
        $comparison = Cache::remember(
            "stats.weekly_volume_comparison.{$user->id}.{$weekKey}",
            now()->addMinutes(10),
            fn (): array => $this->calculatePeriodComparison(
                $user,
                now()->startOfWeek(),
                now()->subWeek()->startOfWeek(),
                now()->subWeek()->endOfWeek()
            )
        );

        return [
            'current_week_volume' => $comparison['current_volume'],
            'previous_week_volume' => $comparison['previous_volume'],
            'difference' => $comparison['difference'],
            'percentage' => $comparison['percentage'],
        ];
    }

    /**
     * @return array<int, array{date: string, duration: int, name: string}>
     */
    public function getDurationHistory(User $user, int $limit = 20): array
    {
        return Cache::remember(
            "stats.duration_history.{$user->id}.{$limit}",
            now()->addMinutes(30),
            fn (): array => DB::table('workouts')
                ->select(['name', 'started_at', 'ended_at'])
                ->where('user_id', $user->id)
                ->whereNotNull('ended_at')
                ->latest('started_at')
                ->take($limit)
                ->get()
                ->map(fn (object $workout): array => $this->formatDurationHistoryItem($workout))
                ->reverse()->values()->toArray()
        );
    }

    /**
     * @return array<int, array{date: string, volume: float, name: string}>
     */
    public function getVolumeHistory(User $user, int $limit = 20): array
    {
        return Cache::remember(
            "stats.volume_history.{$user->id}.{$limit}",
            now()->addMinutes(30),
            fn (): array => $this->fetchVolumeHistory($user, $limit)
        );
    }

    /**
     * @return array{duration: array<int, array{label: string, count: int}>, time_of_day: array<int, array{label: string, count: int}>}
     */
    public function getWorkoutDistributions(User $user, int $days = 90): array
    {
        return Cache::remember(
            "stats.workout_distributions.{$user->id}.{$days}",
            now()->addMinutes(30),
            function () use ($user, $days): array {
                // ⚡ Bolt: PERFORMANCE OPTIMIZATION
                // Consolidate two analytical queries into one. Fetches all workouts in the period
                // once and computes both duration and time-of-day distributions in a single loop.
                $workouts = DB::table('workouts')
                    ->select(['started_at', 'ended_at'])
                    ->where('user_id', $user->id)
                    ->where('started_at', '>=', now()->subDays($days))
                    ->get();

                $durationBuckets = [
                    '< 30 min' => 0,
                    '30-60 min' => 0,
                    '60-90 min' => 0,
                    '90+ min' => 0,
                ];

                $timeOfDayBuckets = [
                    'Matin (06h-12h)' => 0,
                    'Après-midi (12h-17h)' => 0,
                    'Soir (17h-22h)' => 0,
                    'Nuit (22h-06h)' => 0,
                ];

                foreach ($workouts as $workout) {
                    // Time of day calculation
                    $hour = (int) substr((string) $workout->started_at, 11, 2);
                    $timeOfDayBuckets[$this->getBucketForHour($hour)]++;

                    // Duration calculation (only if workout is ended)
                    if ($workout->ended_at) {
                        $minutes = (int) (abs(strtotime((string) $workout->ended_at) - strtotime((string) $workout->started_at)) / 60);
                        $this->incrementBucket($durationBuckets, $minutes);
                    }
                }

                return [
                    'duration' => $this->formatBuckets($durationBuckets),
                    'time_of_day' => $this->formatBuckets($timeOfDayBuckets),
                ];
            }
        );
    }

    /**
     * @return array<int, array{month: string, volume: float}>
     */
    public function getMonthlyVolumeHistory(User $user, int $months = 6): array
    {
        return Cache::remember(
            "stats.monthly_volume_history.{$user->id}.{$months}",
            now()->addMinutes(30),
            function () use ($user, $months): array {
                $data = $this->fetchMonthlyVolumeHistoryData($user, $months);
                /** @var \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, object{volume: float|int}>> $grouped */
                $grouped = $data->groupBy(fn (object $row): string => Carbon::parse($row->started_at ?? (string) now())->format('Y-m'));

                return $this->fillMonthlyVolumeHistory($months, $grouped);
            }
        );
    }

    /**
     * Clear the stats cache for a user.
     */
    public function clearUserStatsCache(User $user): void
    {
        $this->clearWorkoutRelatedStats($user);
        $this->clearWorkoutMetadataStats($user);
        $this->clearBodyMeasurementStats($user);
    }

    /**
     * Clear only metadata related stats (name, notes).
     */
    public function clearWorkoutMetadataStats(User $user): void
    {
        // Metadata (name) is used in volume and duration history
        Cache::forget("stats.volume_history.{$user->id}.20");
        Cache::forget("stats.volume_history.{$user->id}.30");
        Cache::forget("stats.duration_history.{$user->id}.20");

        foreach ([7, 30, 90, 365] as $days) {
            Cache::forget("stats.volume_trend.{$user->id}.{$days}");
        }
    }

    /**
     * Clear stats cache related to workout volume (sets, weight, reps).
     */
    public function clearVolumeStats(User $user): void
    {
        $weekKey = now()->startOfWeek()->format('Y-W');

        Cache::forget("stats.weekly_volume.{$user->id}");
        Cache::forget("stats.weekly_volume_comparison.{$user->id}.{$weekKey}");
        Cache::forget("stats.monthly_volume_comparison.{$user->id}");
        Cache::forget("stats.monthly_volume_history.{$user->id}.6");

        // Invalidate 1RM cache for all exercises (O(1))
        Cache::put("stats.1rm_version.{$user->id}", (string) time(), 86400 * 30);

        // Clear volume trends for common periods
        foreach ([7, 30, 90, 365] as $days) {
            Cache::forget("stats.volume_trend.{$user->id}.{$days}");
            Cache::forget("stats.daily_volume.{$user->id}.{$days}");
        }

        // Clear volume history
        Cache::forget("stats.volume_history.{$user->id}.20");
        Cache::forget("stats.volume_history.{$user->id}.30");

        // Muscle distribution
        Cache::forget("stats.muscle_dist.{$user->id}.30");
        Cache::forget("stats.muscle_dist.{$user->id}.7");
    }

    /**
     * Clear stats cache related to workout duration and time of day.
     */
    public function clearDurationStats(User $user): void
    {
        Cache::forget("stats.duration_history.{$user->id}.20");
        Cache::forget("stats.duration_distribution.{$user->id}.90");
        Cache::forget("stats.time_of_day_distribution.{$user->id}.90");
        Cache::forget("stats.workout_distributions.{$user->id}.90");
    }

    /**
     * Clear all stats cache related to workouts.
     */
    public function clearWorkoutRelatedStats(User $user): void
    {
        $this->clearVolumeStats($user);
        $this->clearDurationStats($user);
    }

    /**
     * Clear stats cache related to body measurements.
     */
    public function clearBodyMeasurementStats(User $user): void
    {
        Cache::forget("stats.latest_metrics.{$user->id}");

        foreach ([7, 30, 90, 365] as $days) {
            Cache::forget("stats.weight_history.{$user->id}.{$days}");
            Cache::forget("stats.body_fat_history.{$user->id}.{$days}");
        }
    }

    /**
     * @return array<int, array{date: string, volume: float, name: string}>
     */
    protected function fetchVolumeHistory(User $user, int $limit): array
    {
        /** @var array<int, array{date: string, volume: float, name: string}> */
        return $this->queryVolumeHistory($user, $limit)
            ->map(fn (object $row): array => $this->formatVolumeHistoryRow($row))
            ->toArray();
    }

    /**
     * @return \Illuminate\Support\Collection<int, object{id: int, started_at: string, name: string, volume: float|int}>
     */
    protected function queryVolumeHistory(User $user, int $limit): \Illuminate\Support\Collection
    {
        /** @var \Illuminate\Support\Collection<int, object{id: int, started_at: string, name: string, volume: float|int}> */
        return DB::table('workouts')
            ->where('user_id', $user->id)
            ->whereNotNull('ended_at')
            ->select(
                'id',
                'started_at',
                'name',
                'workout_volume as volume'
            )
            ->orderBy('started_at')->limit($limit)->get();
    }

    /**
     * @param  object{started_at: string, volume: float|int, name: string}  $row
     * @return array{date: string, volume: float, name: string}
     */
    protected function formatVolumeHistoryRow(object $row): array
    {
        return ['date' => Carbon::parse($row->started_at)->format('d/m'), 'volume' => (float) $row->volume, 'name' => (string) $row->name];
    }

    /**
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, object{volume: float|int}>>  $grouped
     * @return array<int, array{month: string, volume: float}>
     */
    protected function fillMonthlyVolumeHistory(int $months, \Illuminate\Support\Collection $grouped): array
    {
        /** @var array<int, array{month: string, volume: float}> */
        return collect(range($months - 1, 0))
            ->map(fn (int $i): array => $this->formatMonthlyVolumeItem($i, $grouped))
            ->toArray();
    }

    /**
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, object{volume: float|int}>>  $grouped
     * @return array{month: string, volume: float}
     */
    protected function formatMonthlyVolumeItem(int $monthsAgo, \Illuminate\Support\Collection $grouped): array
    {
        $month = now()->subMonths($monthsAgo)->format('Y-m');
        /** @var \Illuminate\Support\Collection<int, object{volume: float|int}>|null $monthData */
        $monthData = $grouped->get($month);
        /** @var int|float|string|null $sum */
        $sum = $monthData ? $monthData->sum('volume') : 0.0;

        return [
            'month' => now()->subMonths($monthsAgo)->translatedFormat('M'),
            'volume' => floatval($sum),
        ];
    }

    protected function getPeriodVolume(User $user, Carbon $start, ?Carbon $end = null): float
    {
        $query = DB::table('workouts')
            ->where('user_id', $user->id);

        if ($end) {
            $query->whereBetween('started_at', [$start, $end]);
        } else {
            $query->where('started_at', '>=', $start);
        }

        /** @var int|float|string|null $sum */
        $sum = $query->sum('workout_volume');

        return floatval($sum);
    }

    protected function getBaseVolumeQuery(User $user): \Illuminate\Database\Query\Builder
    {
        return DB::table('workouts')
            ->join('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->where('workouts.user_id', $user->id);
    }

    /**
     * @return \Illuminate\Support\Collection<string, object{date: string, total_volume: float|int}>
     */
    protected function fetchWeeklyVolumeData(User $user, Carbon $startOfWeek, Carbon $endOfWeek): \Illuminate\Support\Collection
    {
        /** @var \Illuminate\Support\Collection<string, object{date: string, total_volume: float|int}> */
        return DB::table('workouts')
            ->where('user_id', $user->id)
            ->whereBetween('started_at', [$startOfWeek, $endOfWeek])
            ->selectRaw('DATE(started_at) as date, SUM(workout_volume) as total_volume')
            ->groupBy('date')
            ->get()->keyBy('date');
    }

    /**
     * @param  \Illuminate\Support\Collection<string, float>  $results
     * @return array<int, array{date: string, day_name: string, volume: float}>
     */
    protected function fillDailyTrend(Carbon $start, int $days, \Illuminate\Support\Collection $results): array
    {
        $data = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);
            $volume = $results[$date->format('Y-m-d')] ?? 0.0;
            $data[] = ['date' => $date->format('d/m'), 'day_name' => $date->translatedFormat('D'), 'volume' => (float) $volume];
        }

        return $data;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, object{date: string, total_volume: float|int}>  $workouts
     * @return array<int, array{date: string, day_label: string, volume: float}>
     */
    protected function fillWeeklyTrend(Carbon $startOfWeek, \Illuminate\Support\Collection $workouts): array
    {
        $trend = [];
        $labels = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
        for ($i = 0; $i < 7; $i++) {
            $date = $startOfWeek->copy()->addDays($i)->format('Y-m-d');
            /** @var object{total_volume: float|int}|null $workoutData */
            $workoutData = $workouts->get($date);
            $trend[] = ['date' => $date, 'day_label' => $labels[$i], 'volume' => $workoutData ? (float) $workoutData->total_volume : 0.0];
        }

        return $trend;
    }

    /**
     * @param  object{started_at: string, name: string, volume: float|int}  $row
     * @return array{date: string, full_date: string, name: string, volume: float}
     */
    protected function formatVolumeTrendItem(object $row): array
    {
        return ['date' => Carbon::parse($row->started_at)->format('d/m'), 'full_date' => Carbon::parse($row->started_at)->format('Y-m-d'), 'name' => (string) $row->name, 'volume' => (float) $row->volume];
    }

    /**
     * @return \Illuminate\Support\Collection<int, object{id: int, started_at: string, name: string, volume: float|int}>
     */
    protected function fetchVolumeTrendData(User $user, int $days): \Illuminate\Support\Collection
    {
        /** @var \Illuminate\Support\Collection<int, object{id: int, started_at: string, name: string, volume: float|int}> */
        return DB::table('workouts')
            ->where('user_id', $user->id)
            ->where('started_at', '>=', now()->subDays($days))
            ->select(
                'id',
                'started_at',
                'name',
                'workout_volume as volume'
            )
            ->orderBy('started_at')
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<string, float>
     */
    protected function fetchDailyVolumeData(User $user, Carbon $start): \Illuminate\Support\Collection
    {
        return DB::table('workouts')
            ->where('user_id', $user->id)
            ->whereBetween('started_at', [$start, now()->endOfDay()])
            ->selectRaw('DATE(started_at) as date, SUM(workout_volume) as daily_volume')
            ->groupBy('date')
            ->pluck('daily_volume', 'date')
            ->map(fn (mixed $value): float => is_numeric($value) ? floatval($value) : 0.0);
    }

    /**
     * @return \Illuminate\Support\Collection<int, object{started_at: string, volume: float|int}>
     */
    protected function fetchMonthlyVolumeHistoryData(User $user, int $months): \Illuminate\Support\Collection
    {
        /** @var \Illuminate\Support\Collection<int, object{started_at: string, volume: float|int}> */
        return DB::table('workouts')
            ->where('user_id', $user->id)
            ->where('started_at', '>=', now()->subMonths($months - 1)->startOfMonth())
            ->select('started_at', 'workout_volume as volume')
            ->get();
    }

    /**
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
            ->selectRaw('exercises.category, SUM(sets.weight * sets.reps) as volume')
            ->groupBy('exercises.category')
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    protected function fetchExercise1RMData(User $user, int $exerciseId, int $days): \Illuminate\Support\Collection
    {
        return DB::table('sets')->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')->where('workouts.user_id', $user->id)->where('workout_lines.exercise_id', $exerciseId)->where('workouts.started_at', '>=', now()->subDays($days))->selectRaw(
            // SECURITY: Static DB::raw - safe. DO NOT concatenate user input here.
            'workouts.started_at, MAX(sets.weight * (1 + sets.reps / 30.0)) as epley_1rm'
        )->groupBy('workouts.started_at')->orderBy('workouts.started_at')->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    protected function fetchWeightHistoryData(User $user, int $days): \Illuminate\Support\Collection
    {
        // ⚡ Bolt: PERFORMANCE OPTIMIZATION
        // Replaced Eloquent hydration with DB facade for statistical data fetch.
        /** @var \Illuminate\Support\Collection<int, \stdClass> */
        return DB::table('body_measurements')
            ->where('user_id', $user->id)
            ->where('measured_at', '>=', now()->subDays($days))
            ->orderBy('measured_at', 'asc')
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    protected function fetchBodyFatHistoryData(User $user, int $days): \Illuminate\Support\Collection
    {
        // ⚡ Bolt: PERFORMANCE OPTIMIZATION
        // Replaced Eloquent hydration with DB facade for statistical data fetch.
        /** @var \Illuminate\Support\Collection<int, \stdClass> */
        return DB::table('body_measurements')
            ->where('user_id', $user->id)
            ->where('measured_at', '>=', now()->subDays($days))
            ->whereNotNull('body_fat')
            ->orderBy('measured_at', 'asc')
            ->get();
    }

    /**
     * @return array{date: string, full_date: string, weight: float}
     */
    protected function formatWeightHistoryItem(\stdClass $m): array
    {
        $measuredAt = (string) $m->measured_at;

        return [
            'date' => Carbon::parse($measuredAt)->format('d/m'),
            'full_date' => Carbon::parse($measuredAt)->format('Y-m-d'),
            'weight' => (float) $m->weight,
        ];
    }

    /**
     * @return array{date: string, full_date: string, body_fat: float}
     */
    protected function formatBodyFatHistoryItem(\stdClass $m): array
    {
        $measuredAt = (string) $m->measured_at;

        return [
            'date' => Carbon::parse($measuredAt)->format('d/m'),
            'full_date' => Carbon::parse($measuredAt)->format('Y-m-d'),
            'body_fat' => (float) $m->body_fat,
        ];
    }

    /**
     * @return array{current_volume: float, previous_volume: float, difference: float, percentage: float}
     */
    protected function calculatePeriodComparison(User $user, Carbon $currentStart, Carbon $prevStart, ?Carbon $prevEnd = null): array
    {
        $currentVolume = $this->getPeriodVolume($user, $currentStart);
        $previousVolume = $this->getPeriodVolume($user, $prevStart, $prevEnd);
        $diff = $currentVolume - $previousVolume;
        $percentage = $previousVolume > 0 ? $diff / $previousVolume * 100 : ($currentVolume > 0 ? 100 : 0);

        return ['current_volume' => $currentVolume, 'previous_volume' => $previousVolume, 'difference' => $diff, 'percentage' => round($percentage, 1)];
    }

    /**
     * @return array{date: string, full_date: string, one_rep_max: float}
     */
    protected function formatExercise1RMItem(\stdClass $set): array
    {
        return [
            'date' => Carbon::parse($set->started_at)->format('d/m'),
            'full_date' => Carbon::parse($set->started_at)->format('Y-m-d'),
            'one_rep_max' => (float) $set->epley_1rm,
        ];
    }

    /**
     * @return array{date: string, duration: int, name: string}
     */
    protected function formatDurationHistoryItem(object $workout): array
    {
        // ⚡ Bolt: PERFORMANCE OPTIMIZATION
        // Replaced Eloquent model hydration with DB facade.
        // Using Carbon::parse here for correctness with timezones, as the overhead
        // of instantiating 20 objects is negligible compared to full model hydration.
        $startedAt = Carbon::parse((string) ($workout->started_at ?? 'now'));
        $endedAt = ! empty($workout->ended_at) ? Carbon::parse((string) $workout->ended_at) : null;

        return [
            'date' => $startedAt->format('d/m'),
            'duration' => $endedAt ? (int) abs($startedAt->diffInMinutes($endedAt)) : 0,
            'name' => (string) ($workout->name ?? 'Séance'),
        ];
    }

    /**
     * @param  array<string, int>  $buckets
     * @return array<int, array{label: string, count: int}>
     */
    private function formatBuckets(array $buckets): array
    {
        return collect($buckets)->map(fn (int $count, string $label): array => ['label' => $label, 'count' => $count])->values()->all();
    }

    private function getBucketForHour(int $hour): string
    {
        return match (true) {
            $hour >= 6 && $hour < 12 => 'Matin (06h-12h)',
            $hour >= 12 && $hour < 17 => 'Après-midi (12h-17h)',
            $hour >= 17 && $hour < 22 => 'Soir (17h-22h)',
            default => 'Nuit (22h-06h)',
        };
    }

    /**
     * @param  array<string, int>  $buckets
     */
    private function incrementBucket(array &$buckets, int $minutes): void
    {
        $label = match (true) {
            $minutes < 30 => '< 30 min',
            $minutes < 60 => '30-60 min',
            $minutes < 90 => '60-90 min',
            default => '90+ min',
        };

        $buckets[$label]++;
    }
}
