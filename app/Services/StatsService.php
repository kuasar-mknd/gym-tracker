<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Workout;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service for calculating and retrieving user workout statistics.
 */
class StatsService
{
    /**
     * @return array<int, array{date: string, full_date: string, name: string, volume: float}>
     */
    public function getVolumeTrend(User $user, int $days = 30): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.volume_trend.{$user->id}.{$days}",
            now()->addMinutes(30),
            fn (): array => $this->fetchVolumeTrendData($user, $days)
                ->map(fn (\stdClass $row): array => $this->formatVolumeTrendItem($row))
                ->values()
                ->toArray()
        );
    }

    /**
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
     * @return array<int, array{category: string, volume: float}>
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
     * @return array<int, array{date: string, full_date: string, one_rep_max: float}>
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
     * @return array{current_month_volume: float, previous_month_volume: float, difference: float, percentage: float}
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
     * @return array<int, array{date: string, full_date: string, weight: float}>
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
     * @return array{latest_weight: float|null, weight_change: float, latest_body_fat: float|null}
     */
    public function getLatestBodyMetrics(User $user): array
    {
        $measurements = $user->bodyMeasurements()->latest('measured_at')->take(2)->get();
        $latest = $measurements->first();
        $previous = $measurements->skip(1)->first();

        $weightChange = $latest && $previous ? round($latest->weight - $previous->weight, 1) : 0;

        return [
            'latest_weight' => $latest?->weight ? (float) $latest->weight : null,
            'weight_change' => (float) $weightChange,
            'latest_body_fat' => $latest?->body_fat ? (float) $latest->body_fat : null,
        ];
    }

    /**
     * @return array<int, array{date: string, full_date: string, body_fat: float}>
     */
    public function getBodyFatHistory(User $user, int $days = 90): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.body_fat_history.{$user->id}.{$days}",
            now()->addMinutes(30),
            fn (): array => $this->fetchBodyFatHistoryData($user, $days)
                ->map(fn (\App\Models\BodyMeasurement $m): array => $this->formatBodyFatHistoryItem($m))
                ->toArray()
        );
    }

    /**
     * @return array<int, array{date: string, day_label: string, volume: float}>
     */
    public function getWeeklyVolumeTrend(User $user): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.weekly_volume.{$user->id}",
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
        $comparison = \Illuminate\Support\Facades\Cache::remember(
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
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.duration_history.{$user->id}.{$limit}",
            now()->addMinutes(30),
            fn (): array => Workout::select(['name', 'started_at', 'ended_at'])
                ->where('user_id', $user->id)
                ->whereNotNull('ended_at')
                ->latest('started_at')
                ->take($limit)
                ->get()
                ->map(fn (\App\Models\Workout $workout): array => $this->formatDurationHistoryItem($workout))
                ->reverse()->values()->toArray()
        );
    }

    /**
     * @return array<int, array{date: string, volume: float, name: string}>
     */
    public function getVolumeHistory(User $user, int $limit = 20): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.volume_history.{$user->id}.{$limit}",
            now()->addMinutes(30),
            fn (): array => $this->fetchVolumeHistory($user, $limit)
        );
    }

    /**
     * @return array<int, array{label: string, count: int}>
     */
    public function getDurationDistribution(User $user, int $days = 90): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.duration_distribution.{$user->id}.{$days}",
            now()->addMinutes(30),
            fn (): array => $this->calculateDurationDistribution($user, $days)
        );
    }

    /**
     * @return array<int, array{month: string, volume: float}>
     */
    public function getMonthlyVolumeHistory(User $user, int $months = 6): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "stats.monthly_volume_history.{$user->id}.{$months}",
            now()->addMinutes(30),
            function () use ($user, $months): array {
                $data = $this->fetchMonthlyVolumeHistoryData($user, $months);
                $grouped = $data->groupBy(fn ($row): string => Carbon::parse($row->started_at)->format('Y-m'));

                return $this->fillMonthlyVolumeHistory($months, $grouped);
            }
        );
    }

    public function clearUserStatsCache(User $user): void
    {
        $this->clearWorkoutRelatedStats($user);
        $this->clearBodyMeasurementStats($user);
    }

    public function clearWorkoutRelatedStats(User $user): void
    {
        $this->clearWorkoutTrendStats($user);
        $this->clearWorkoutMetadataStats($user);

        \Illuminate\Support\Facades\Cache::forget("stats.weekly_volume.{$user->id}");
        $weekKey = now()->startOfWeek()->format('Y-W');
        \Illuminate\Support\Facades\Cache::forget("stats.weekly_volume_comparison.{$user->id}.{$weekKey}");
        \Illuminate\Support\Facades\Cache::forget("stats.monthly_volume_comparison.{$user->id}");
        \Illuminate\Support\Facades\Cache::forget("stats.duration_distribution.{$user->id}.90");
        \Illuminate\Support\Facades\Cache::forget("stats.monthly_volume_history.{$user->id}.6");
    }

    public function clearWorkoutMetadataStats(User $user): void
    {
        \Illuminate\Support\Facades\Cache::forget("dashboard_data_{$user->id}");

        $periods = [7, 30, 90, 365];
        foreach ($periods as $days) {
            \Illuminate\Support\Facades\Cache::forget("stats.volume_trend.{$user->id}.{$days}");
        }

        \Illuminate\Support\Facades\Cache::forget("stats.duration_history.{$user->id}.20");
        \Illuminate\Support\Facades\Cache::forget("stats.duration_history.{$user->id}.30");
        \Illuminate\Support\Facades\Cache::forget("stats.volume_history.{$user->id}.20");
        \Illuminate\Support\Facades\Cache::forget("stats.volume_history.{$user->id}.30");
    }

    public function clearBodyMeasurementStats(User $user): void
    {
        $periods = [7, 30, 90, 365];
        foreach ($periods as $days) {
            \Illuminate\Support\Facades\Cache::forget("stats.weight_history.{$user->id}.{$days}");
            \Illuminate\Support\Facades\Cache::forget("stats.body_fat_history.{$user->id}.{$days}");
        }

        \Illuminate\Support\Facades\Cache::forget("dashboard_data_{$user->id}");
    }

    /**
     * @return array<int, array{date: string, volume: float, name: string}>
     */
    protected function fetchVolumeHistory(User $user, int $limit): array
    {
        return $this->queryVolumeHistory($user, $limit)
            ->map(fn (object $row): array => $this->formatVolumeHistoryRow($row))
            ->reverse()->values()->toArray();
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    protected function queryVolumeHistory(User $user, int $limit): \Illuminate\Support\Collection
    {
        return DB::table('workouts')
            ->leftJoin('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')
            ->leftJoin('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->where('workouts.user_id', $user->id)
            ->whereNotNull('workouts.ended_at')
            ->select('workouts.id', 'workouts.started_at', 'workouts.name', DB::raw('COALESCE(SUM(sets.weight * sets.reps), 0) as volume'))
            ->groupBy('workouts.id', 'workouts.started_at', 'workouts.name')
            ->orderByDesc('workouts.started_at')->limit($limit)->get()->map(fn (object $row): object => $row);
    }

    /**
     * @return array{date: string, volume: float, name: string}
     */
    protected function formatVolumeHistoryRow(object $row): array
    {
        return ['date' => Carbon::parse($row->started_at)->format('d/m'), 'volume' => (float) $row->volume, 'name' => (string) $row->name];
    }

    /**
     * @return array<int, array{label: string, count: int}>
     */
    protected function calculateDurationDistribution(User $user, int $days): array
    {
        $workouts = Workout::select(['started_at', 'ended_at'])->where('user_id', $user->id)->whereNotNull('ended_at')->where('started_at', '>=', now()->subDays($days))->get();
        $buckets = ['< 30 min' => 0, '30-60 min' => 0, '60-90 min' => 0, '90+ min' => 0];

        foreach ($workouts as $workout) {
            $minutes = abs((int) $workout->ended_at?->diffInMinutes($workout->started_at));
            $this->incrementBucket($buckets, $minutes);
        }

        return collect($buckets)->map(fn (int $count, string $label): array => ['label' => $label, 'count' => $count])->values()->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, \stdClass>>  $grouped
     * @return array<int, array{month: string, volume: float}>
     */
    protected function fillMonthlyVolumeHistory(int $months, \Illuminate\Support\Collection $grouped): array
    {
        $result = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $monthData = $grouped->get($date->format('Y-m'));
            $sum = $monthData ? $monthData->sum('volume') : 0.0;
            $result[] = ['month' => $date->translatedFormat('M'), 'volume' => is_numeric($sum) ? floatval($sum) : 0.0];
        }

        return $result;
    }

    protected function clearWorkoutTrendStats(User $user): void
    {
        $periods = [7, 30, 90, 365];
        foreach ($periods as $days) {
            \Illuminate\Support\Facades\Cache::forget("stats.daily_volume.{$user->id}.{$days}");
            \Illuminate\Support\Facades\Cache::forget("stats.muscle_dist.{$user->id}.{$days}");
        }
    }

    protected function getPeriodVolume(User $user, Carbon $start, ?Carbon $end = null): float
    {
        $query = DB::table('sets')->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')->where('workouts.user_id', $user->id);
        if ($end) {
            $query->whereBetween('workouts.started_at', [$start, $end]);
        } else {
            $query->where('workouts.started_at', '>=', $start);
        }

        return (float) $query->sum(DB::raw('sets.weight * sets.reps'));
    }

    /**
     * @return \Illuminate\Support\Collection<string, object>
     */
    protected function fetchWeeklyVolumeData(User $user, Carbon $startOfWeek, Carbon $endOfWeek): \Illuminate\Support\Collection
    {
        return DB::table('workouts')->leftJoin('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')->leftJoin('sets', 'workout_lines.id', '=', 'sets.workout_line_id')->where('workouts.user_id', $user->id)->whereBetween('workouts.started_at', [$startOfWeek, $endOfWeek])->select(DB::raw('DATE(workouts.started_at) as date'), DB::raw('COALESCE(SUM(sets.weight * sets.reps), 0) as volume'))->groupBy(DB::raw('DATE(workouts.started_at)'))->get()->keyBy(fn (object $item): string => (string) $item->date);
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
     * @param  \Illuminate\Support\Collection<string, object>  $workouts
     * @return array<int, array{date: string, day_label: string, volume: float}>
     */
    protected function fillWeeklyTrend(Carbon $startOfWeek, \Illuminate\Support\Collection $workouts): array
    {
        $trend = [];
        $labels = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
        for ($i = 0; $i < 7; $i++) {
            $date = $startOfWeek->copy()->addDays($i)->format('Y-m-d');
            /** @var object|null $workout */
            $workout = $workouts->get($date);
            $trend[] = ['date' => $date, 'day_label' => $labels[$i], 'volume' => $workout ? (float) $workout->volume : 0.0];
        }

        return $trend;
    }

    /**
     * @return array{date: string, full_date: string, name: string, volume: float}
     */
    protected function formatVolumeTrendItem(\stdClass $row): array
    {
        return ['date' => Carbon::parse($row->started_at)->format('d/m'), 'full_date' => Carbon::parse($row->started_at)->format('Y-m-d'), 'name' => (string) $row->name, 'volume' => (float) $row->volume];
    }

    /**
     * @return array{date: string, full_date: string, one_rep_max: float}
     */
    protected function formatExercise1RMItem(\stdClass $set): array
    {
        return ['date' => Carbon::parse($set->started_at)->format('d/m'), 'full_date' => Carbon::parse($set->started_at)->format('Y-m-d'), 'one_rep_max' => round((float) $set->epley_1rm, 2)];
    }

    /**
     * @return array{date: string, duration: int, name: string}
     */
    protected function formatDurationHistoryItem(Workout $workout): array
    {
        return ['date' => $workout->started_at->format('d/m'), 'duration' => (int) ($workout->ended_at ? $workout->ended_at->diffInMinutes($workout->started_at, true) : 0), 'name' => (string) $workout->name];
    }

    /**
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    protected function fetchVolumeTrendData(User $user, int $days): \Illuminate\Support\Collection
    {
        return DB::table('workouts')->leftJoin('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')->leftJoin('sets', 'workout_lines.id', '=', 'sets.workout_line_id')->where('workouts.user_id', $user->id)->where('workouts.started_at', '>=', now()->subDays($days))->select('workouts.id', 'workouts.started_at', 'workouts.name', DB::raw('COALESCE(SUM(sets.weight * sets.reps), 0) as volume'))->groupBy('workouts.id', 'workouts.started_at', 'workouts.name')->orderBy('workouts.started_at')->get();
    }

    /**
     * @return \Illuminate\Support\Collection<string, float>
     */
    protected function fetchDailyVolumeData(User $user, Carbon $start): \Illuminate\Support\Collection
    {
        return DB::table('workouts')->leftJoin('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')->leftJoin('sets', 'workout_lines.id', '=', 'sets.workout_line_id')->where('workouts.user_id', $user->id)->whereBetween('workouts.started_at', [$start, now()->endOfDay()])->select(DB::raw('DATE(workouts.started_at) as date'), DB::raw('COALESCE(SUM(sets.weight * sets.reps), 0) as volume'))->groupBy('date')->pluck('volume', 'date')->map(fn (mixed $value): float => is_numeric($value) ? floatval($value) : 0.0);
    }

    /**
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    protected function fetchMonthlyVolumeHistoryData(User $user, int $months): \Illuminate\Support\Collection
    {
        return DB::table('workouts')->leftJoin('workout_lines', 'workouts.id', '=', 'workout_lines.workout_id')->leftJoin('sets', 'workout_lines.id', '=', 'sets.workout_line_id')->where('workouts.user_id', $user->id)->where('workouts.started_at', '>=', now()->subMonths($months - 1)->startOfMonth())->select('workouts.started_at', DB::raw('COALESCE(SUM(sets.weight * sets.reps), 0) as volume'))->groupBy('workouts.id', 'workouts.started_at')->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    protected function fetchMuscleDistributionData(User $user, int $days): \Illuminate\Support\Collection
    {
        return DB::table('sets')->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')->join('exercises', 'workout_lines.exercise_id', '=', 'exercises.id')->where('workouts.user_id', $user->id)->where('workouts.started_at', '>=', now()->subDays($days))->selectRaw('exercises.category, SUM(sets.weight * sets.reps) as volume')->groupBy('exercises.category')->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    protected function fetchExercise1RMData(User $user, int $exerciseId, int $days): \Illuminate\Support\Collection
    {
        return DB::table('sets')->join('workout_lines', 'sets.workout_line_id', '=', 'workout_lines.id')->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')->where('workouts.user_id', $user->id)->where('workout_lines.exercise_id', $exerciseId)->where('workouts.started_at', '>=', now()->subDays($days))->selectRaw('workouts.started_at, MAX(sets.weight * (1 + sets.reps / 30.0)) as epley_1rm')->groupBy('workouts.started_at')->orderBy('workouts.started_at')->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\BodyMeasurement>
     */
    protected function fetchWeightHistoryData(User $user, int $days): \Illuminate\Database\Eloquent\Collection
    {
        return $user->bodyMeasurements()->where('measured_at', '>=', now()->subDays($days))->orderBy('measured_at', 'asc')->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\BodyMeasurement>
     */
    protected function fetchBodyFatHistoryData(User $user, int $days): \Illuminate\Database\Eloquent\Collection
    {
        return $user->bodyMeasurements()->where('measured_at', '>=', now()->subDays($days))->whereNotNull('body_fat')->orderBy('measured_at', 'asc')->get();
    }

    /**
     * @return array{date: string, full_date: string, weight: float}
     */
    protected function formatWeightHistoryItem(\App\Models\BodyMeasurement $m): array
    {
        return ['date' => Carbon::parse($m->measured_at)->format('d/m'), 'full_date' => Carbon::parse($m->measured_at)->format('Y-m-d'), 'weight' => (float) $m->weight];
    }

    /**
     * @return array{date: string, full_date: string, body_fat: float}
     */
    protected function formatBodyFatHistoryItem(\App\Models\BodyMeasurement $m): array
    {
        return ['date' => Carbon::parse($m->measured_at)->format('d/m'), 'full_date' => Carbon::parse($m->measured_at)->format('Y-m-d'), 'body_fat' => (float) $m->body_fat];
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
     * @param  array<string, int>  $buckets
     */
    private function incrementBucket(array &$buckets, int $minutes): void
    {
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
}
