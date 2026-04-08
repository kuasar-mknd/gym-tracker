<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\DTOs\Stats\DailyVolumeTrendPoint;
use App\DTOs\Stats\MonthlyVolumePoint;
use App\DTOs\Stats\VolumeComparison;
use App\DTOs\Stats\VolumeHistoryPoint;
use App\DTOs\Stats\VolumeTrendPoint;
use App\DTOs\Stats\WeeklyVolumeTrendPoint;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

final class VolumeStatsService
{
    /**
     * @return array<int, VolumeTrendPoint>
     */
    public function getVolumeTrend(User $user, int $days = 30): array
    {
        return Cache::remember(
            "stats.volume_trend.{$user->id}.{$days}",
            now()->addMinutes(30),
            fn (): array => $user->workouts()
                // ⚡ Bolt: PERFORMANCE OPTIMIZATION
                // Use toBase() to avoid hydrating Eloquent models and instantiating Carbon objects.
                // This significantly reduces memory usage and execution time for large datasets.
                ->toBase()
                ->where('started_at', '>=', now()->subDays($days))
                ->select(['id', 'started_at', 'name', 'workout_volume as volume'])
                ->orderBy('started_at')
                ->get()
                ->map(function (object $row): ?VolumeTrendPoint {
                    $timestamp = strtotime((string) $row->started_at);

                    if ($timestamp === false) {
                        return null;
                    }

                    return new VolumeTrendPoint(
                        date('d/m', $timestamp),
                        date('Y-m-d', $timestamp),
                        (string) $row->name,
                        is_numeric($row->volume) ? (float) $row->volume : 0.0,
                    );
                })
                ->filter()
                ->values()
                ->toArray()
        );
    }

    /**
     * @return array<int, DailyVolumeTrendPoint>
     */
    public function getDailyVolumeTrend(User $user, int $days = 7): array
    {
        return Cache::remember(
            "stats.daily_volume.{$user->id}.{$days}",
            now()->addMinutes(30),
            function () use ($user, $days): array {
                $start = now()->subDays($days - 1)->startOfDay();
                $results = $user->workouts()
                    ->whereBetween('started_at', [$start, now()->endOfDay()])
                    ->selectRaw('DATE(started_at) as date, SUM(workout_volume) as daily_volume')
                    ->groupBy('date')
                    ->pluck('daily_volume', 'date')
                    ->map(fn (mixed $value): float => is_numeric($value) ? floatval($value) : 0.0);

                $data = [];
                for ($i = 0; $i < $days; $i++) {
                    $date = $start->copy()->addDays($i);
                    $volume = $results[$date->format('Y-m-d')] ?? 0.0;
                    $data[] = new DailyVolumeTrendPoint(
                        $date->format('d/m'),
                        $date->translatedFormat('D'),
                        (float) $volume,
                    );
                }

                return $data;
            }
        );
    }

    /**
     * @return array<int, WeeklyVolumeTrendPoint>
     */
    public function getWeeklyVolumeTrend(User $user): array
    {
        return Cache::remember(
            "stats.weekly_volume.{$user->id}",
            now()->addMinutes(10),
            function () use ($user): array {
                $startOfWeek = now()->startOfWeek();
                $endOfWeek = now()->endOfWeek();

                // ⚡ Bolt: PERFORMANCE OPTIMIZATION
                // Use toBase() to avoid hydrating Eloquent models.
                $workouts = $user->workouts()
                    ->toBase()
                    ->whereBetween('started_at', [$startOfWeek, $endOfWeek])
                    ->selectRaw('DATE(started_at) as date, SUM(workout_volume) as total_volume')
                    ->groupBy('date')
                    ->get()
                    ->keyBy('date');

                $trend = [];
                for ($i = 0; $i < 7; $i++) {
                    $dateObj = $startOfWeek->copy()->addDays($i);
                    $date = $dateObj->format('Y-m-d');
                    $workoutData = $workouts->get($date);
                    $trend[] = new WeeklyVolumeTrendPoint(
                        $date,
                        ucfirst($dateObj->translatedFormat('D')),
                        $workoutData && is_numeric($workoutData->total_volume) ? (float) $workoutData->total_volume : 0.0,
                    );
                }

                return $trend;
            }
        );
    }

    /**
     * @return array<int, VolumeHistoryPoint>
     */
    public function getVolumeHistory(User $user, int $limit = 20): array
    {
        return Cache::remember(
            "stats.volume_history.{$user->id}.{$limit}",
            now()->addMinutes(30),
            fn (): array => $user->workouts()
                // ⚡ Bolt: PERFORMANCE OPTIMIZATION
                // Use toBase() to avoid hydrating Eloquent models and instantiating Carbon objects.
                // This significantly reduces memory usage and execution time for large datasets.
                ->toBase()
                ->whereNotNull('ended_at')
                ->select(['id', 'started_at', 'name', 'workout_volume as volume'])
                ->orderBy('started_at')
                ->limit($limit)
                ->get()
                ->map(function (object $row): ?VolumeHistoryPoint {
                    $timestamp = strtotime((string) $row->started_at);

                    if ($timestamp === false) {
                        return null;
                    }

                    return new VolumeHistoryPoint(
                        date('d/m', $timestamp),
                        is_numeric($row->volume) ? (float) $row->volume : 0.0,
                        (string) $row->name,
                    );
                })
                ->filter()
                ->toArray()
        );
    }

    public function getMonthlyVolumeComparison(User $user): VolumeComparison
    {
        $comparison = $this->calculateComparison(
            $user,
            now()->startOfMonth(),
            now()->subMonth()->startOfMonth(),
            now()->subMonth()->endOfMonth()
        );

        return new VolumeComparison(
            $comparison['current_volume'],
            $comparison['previous_volume'],
            $comparison['difference'],
            $comparison['percentage'],
        );
    }

    public function getWeeklyVolumeComparison(User $user): VolumeComparison
    {
        $weekKey = now()->startOfWeek()->format('Y-W');

        $comparison = Cache::remember(
            "stats.weekly_volume_comparison.{$user->id}.{$weekKey}",
            now()->addMinutes(10),
            fn (): array => $this->calculateComparison(
                $user,
                now()->startOfWeek(),
                now()->subWeek()->startOfWeek(),
                now()->subWeek()->endOfWeek()
            )
        );

        return new VolumeComparison(
            $comparison['current_volume'],
            $comparison['previous_volume'],
            $comparison['difference'],
            $comparison['percentage'],
        );
    }

    /**
     * @return array<int, MonthlyVolumePoint>
     */
    public function getMonthlyVolumeHistory(User $user, int $months = 6): array
    {
        return Cache::remember(
            "stats.monthly_volume_history.{$user->id}.{$months}",
            now()->addMinutes(30),
            function () use ($user, $months): array {
                // ⚡ Bolt: PERFORMANCE OPTIMIZATION
                // Perform grouping and summation directly in SQL to reduce memory usage and CPU cycles in PHP.
                // Uses toBase() to bypass Eloquent model hydration and a driver-aware format for database portability.
                $driver = \Illuminate\Support\Facades\DB::getDriverName();
                $monthFormat = $driver === 'sqlite' ? "strftime('%Y-%m', started_at)" : "DATE_FORMAT(started_at, '%Y-%m')";

                $results = $user->workouts()
                    ->toBase()
                    ->where('started_at', '>=', now()->subMonths($months - 1)->startOfMonth())
                    ->selectRaw("{$monthFormat} as month, SUM(workout_volume) as volume")
                    ->groupBy('month')
                    ->pluck('volume', 'month');

                return collect(range($months - 1, 0))
                    ->map(function (int $i) use ($results): MonthlyVolumePoint {
                        $date = now()->subMonths($i);
                        $monthKey = $date->format('Y-m');
                        $sum = $results->get($monthKey) ?? 0.0;

                        return new MonthlyVolumePoint(
                            $date->translatedFormat('M'),
                            is_numeric($sum) ? (float) $sum : 0.0,
                        );
                    })
                    ->toArray();
            }
        );
    }

    /**
     * @return array{current_volume: float, previous_volume: float, difference: float, percentage: float}
     */
    private function calculateComparison(User $user, Carbon $currentStart, Carbon $prevStart, ?Carbon $prevEnd = null): array
    {
        // ⚡ Bolt: PERFORMANCE OPTIMIZATION
        // Consolidate two SUM queries into a single database query using conditional aggregation.
        // Also uses toBase() to bypass Eloquent overhead.
        $query = $user->workouts()
            ->toBase()
            ->where('started_at', '>=', $prevStart);

        if ($prevEnd) {
            $query->selectRaw('
                SUM(CASE WHEN started_at >= ? THEN workout_volume ELSE 0 END) as current_volume,
                SUM(CASE WHEN started_at <= ? THEN workout_volume ELSE 0 END) as previous_volume
            ', [$currentStart, $prevEnd]);
        } else {
            // ⚡ Bolt: Preserve exactly the original behavior where 'previous_volume' is the sum for the whole query if $prevEnd is null.
            $query->selectRaw('
                SUM(CASE WHEN started_at >= ? THEN workout_volume ELSE 0 END) as current_volume,
                SUM(workout_volume) as previous_volume
            ', [$currentStart]);
        }

        /** @var \stdClass|null $stats */
        $stats = $query->first();

        $currentVolume = is_numeric($stats?->current_volume) ? (float) $stats->current_volume : 0.0;
        $previousVolume = is_numeric($stats?->previous_volume) ? (float) $stats->previous_volume : 0.0;

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
