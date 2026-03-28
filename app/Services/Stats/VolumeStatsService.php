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

                $workouts = $user->workouts()
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
                        $workoutData && is_numeric($workoutData->getAttribute('total_volume')) ? (float) $workoutData->getAttribute('total_volume') : 0.0,
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

    public function getMonthlyVolumeComparison(User $user): VolumeComparison
    {
        $now = now();
        $currentStart = $now->copy()->startOfMonth();
        $prevStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $prevEnd = $now->copy()->subMonthNoOverflow()->endOfMonth();

        $comparison = $this->calculateComparison(
            $user,
            $currentStart,
            $prevStart,
            $prevEnd
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

    private function getPeriodVolume(User $user, Carbon $start, ?Carbon $end = null): float
    {
        $query = $user->workouts()
            ->where('user_id', $user->id);

        if ($end) {
            $query->whereBetween('started_at', [$start->toDateTimeString(), $end->toDateTimeString()]);
        } else {
            $query->where('started_at', '>=', $start->toDateTimeString());
        }

        return (float) $query->sum('workout_volume');
    }
}
