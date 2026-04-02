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

/**
 * Service for calculating and retrieving user volume statistics.
 *
 * This service handles all calculations related to lifted volume, including
 * trends over time, daily/weekly/monthly aggregations, and comparisons
 * between different periods.
 */
final class VolumeStatsService
{
    /**
     * Get the volume trend over a specified number of days.
     *
     * @param  User  $user  The user to retrieve the trend for.
     * @param  int  $days   The number of days to look back.
     * @return array<int, VolumeTrendPoint>
     */
    public function getVolumeTrend(User $user, int $days = 30): array
    {
        return Cache::remember(
            "stats.volume_trend.{$user->id}.{$days}",
            now()->addMinutes(30),
            fn (): array => $user->workouts()
                ->where('started_at', '>=', now()->subDays($days))
                ->select(['id', 'started_at', 'name', 'workout_volume as volume'])
                ->orderBy('started_at')
                ->get()
                ->map(fn (object $row): VolumeTrendPoint => new VolumeTrendPoint(
                    Carbon::parse($row->started_at)->format('d/m'),
                    Carbon::parse($row->started_at)->format('Y-m-d'),
                    (string) $row->name,
                    is_numeric($row->getAttribute('volume')) ? (float) $row->getAttribute('volume') : 0.0,
                ))
                ->values()
                ->toArray()
        );
    }

    /**
     * Get the aggregated daily volume trend.
     *
     * @param  User  $user  The user to retrieve the daily trend for.
     * @param  int  $days   The number of days to include in the trend.
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
     * Get the aggregated weekly volume trend for the current week.
     *
     * @param  User  $user  The user to retrieve the weekly trend for.
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
     * Get the volume history for the user's most recent workouts.
     *
     * @param  User  $user  The user to retrieve the volume history for.
     * @param  int  $limit  The maximum number of recent workouts to include.
     * @return array<int, VolumeHistoryPoint>
     */
    public function getVolumeHistory(User $user, int $limit = 20): array
    {
        return Cache::remember(
            "stats.volume_history.{$user->id}.{$limit}",
            now()->addMinutes(30),
            fn (): array => $user->workouts()
                ->whereNotNull('ended_at')
                ->select(['id', 'started_at', 'name', 'workout_volume as volume'])
                ->orderBy('started_at')
                ->limit($limit)
                ->get()
                ->map(fn (object $row): VolumeHistoryPoint => new VolumeHistoryPoint(
                    Carbon::parse($row->started_at)->format('d/m'),
                    is_numeric($row->getAttribute('volume')) ? (float) $row->getAttribute('volume') : 0.0,
                    (string) $row->name,
                ))
                ->toArray()
        );
    }

    /**
     * Compare the current month's volume to the previous month's volume.
     *
     * @param  User  $user  The user to calculate the comparison for.
     * @return VolumeComparison
     */
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

    /**
     * Compare the current week's volume to the previous week's volume.
     *
     * @param  User  $user  The user to calculate the comparison for.
     * @return VolumeComparison
     */
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
     * Get the aggregated monthly volume history.
     *
     * @param  User  $user    The user to retrieve the monthly history for.
     * @param  int  $months   The number of months to look back.
     * @return array<int, MonthlyVolumePoint>
     */
    public function getMonthlyVolumeHistory(User $user, int $months = 6): array
    {
        return Cache::remember(
            "stats.monthly_volume_history.{$user->id}.{$months}",
            now()->addMinutes(30),
            function () use ($user, $months): array {
                $data = $user->workouts()
                    ->where('started_at', '>=', now()->subMonths($months - 1)->startOfMonth())
                    ->select(['started_at', 'workout_volume as volume'])
                    ->get();

                $grouped = $data->groupBy(fn (object $row): string => Carbon::parse($row->started_at ?? (string) now())->format('Y-m'));

                return collect(range($months - 1, 0))
                    ->map(function (int $i) use ($grouped): MonthlyVolumePoint {
                        $date = now()->subMonths($i);
                        $month = $date->format('Y-m');
                        $monthData = $grouped->get($month);
                        $sum = $monthData ? $monthData->sum('volume') : 0.0;

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
     * Calculate a comparison of volume between two periods.
     *
     * @param  User  $user          The user to calculate the comparison for.
     * @param  Carbon  $currentStart The start date of the current period.
     * @param  Carbon  $prevStart    The start date of the previous period.
     * @param  Carbon|null  $prevEnd The end date of the previous period.
     * @return array{current_volume: float, previous_volume: float, difference: float, percentage: float}
     */
    private function calculateComparison(User $user, Carbon $currentStart, Carbon $prevStart, ?Carbon $prevEnd = null): array
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

    /**
     * Get the total volume for a specific time period.
     *
     * @param  User  $user        The user to calculate the period volume for.
     * @param  Carbon  $start     The start date of the period.
     * @param  Carbon|null  $end  The end date of the period.
     * @return float
     */
    private function getPeriodVolume(User $user, Carbon $start, ?Carbon $end = null): float
    {
        $query = $user->workouts();

        if ($end) {
            $query->whereBetween('started_at', [$start, $end]);
        } else {
            $query->where('started_at', '>=', $start);
        }

        return (float) $query->sum('workout_volume');
    }
}
