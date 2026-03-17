<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class VolumeStatsService
{
    /**
     * @return array<int, array{date: string, full_date: string, name: string, volume: float}>
     */
    public function getVolumeTrend(User $user, int $days = 30): array
    {
        return Cache::remember(
            "stats.volume_trend.{$user->id}.{$days}",
            now()->addMinutes(30),
            fn (): array => DB::table('workouts')
                ->where('user_id', $user->id)
                ->where('started_at', '>=', now()->subDays($days))
                ->select(['id', 'started_at', 'name', 'workout_volume as volume'])
                ->orderBy('started_at')
                ->get()
                ->map(fn (object $row): array => [
                    'date' => Carbon::parse($row->started_at)->format('d/m'),
                    'full_date' => Carbon::parse($row->started_at)->format('Y-m-d'),
                    'name' => (string) $row->name,
                    'volume' => (float) $row->volume,
                ])
                ->values()
                ->toArray()
        );
    }

    /**
     * @return array<int, array{date: string, day_name: string, volume: float}>
     */
    public function getDailyVolumeTrend(User $user, int $days = 7): array
    {
        return Cache::remember(
            "stats.daily_volume.{$user->id}.{$days}",
            now()->addMinutes(30),
            function () use ($user, $days): array {
                $start = now()->subDays($days - 1)->startOfDay();
                $results = DB::table('workouts')
                    ->where('user_id', $user->id)
                    ->whereBetween('started_at', [$start, now()->endOfDay()])
                    ->selectRaw('DATE(started_at) as date, SUM(workout_volume) as daily_volume')
                    ->groupBy('date')
                    ->pluck('daily_volume', 'date')
                    ->map(fn (mixed $value): float => is_numeric($value) ? floatval($value) : 0.0);

                $data = [];
                for ($i = 0; $i < $days; $i++) {
                    $date = $start->copy()->addDays($i);
                    $volume = $results[$date->format('Y-m-d')] ?? 0.0;
                    $data[] = [
                        'date' => $date->format('d/m'),
                        'day_name' => $date->translatedFormat('D'),
                        'volume' => (float) $volume,
                    ];
                }

                return $data;
            }
        );
    }

    /**
     * @return array<int, array{date: string, day_label: string, volume: float}>
     */
    public function getWeeklyVolumeTrend(User $user): array
    {
        return Cache::remember(
            "stats.weekly_volume.{$user->id}",
            now()->addMinutes(10),
            function () use ($user): array {
                $startOfWeek = now()->startOfWeek();
                $endOfWeek = now()->endOfWeek();

                $workouts = DB::table('workouts')
                    ->where('user_id', $user->id)
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
                    $trend[] = [
                        'date' => $date,
                        'day_label' => ucfirst($dateObj->translatedFormat('D')),
                        'volume' => $workoutData ? (float) $workoutData->total_volume : 0.0,
                    ];
                }

                return $trend;
            }
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
            fn (): array => DB::table('workouts')
                ->where('user_id', $user->id)
                ->whereNotNull('ended_at')
                ->select(['id', 'started_at', 'name', 'workout_volume as volume'])
                ->orderBy('started_at')
                ->limit($limit)
                ->get()
                ->map(fn (object $row): array => [
                    'date' => Carbon::parse($row->started_at)->format('d/m'),
                    'volume' => (float) $row->volume,
                    'name' => (string) $row->name,
                ])
                ->toArray()
        );
    }

    /**
     * @return array{current_month_volume: float, previous_month_volume: float, difference: float, percentage: float}
     */
    public function getMonthlyVolumeComparison(User $user): array
    {
        $comparison = $this->calculateComparison(
            $user,
            now()->startOfMonth(),
            now()->subMonth()->startOfMonth(),
            now()->subMonth()->endOfMonth()
        );

        return [
            'current_month_volume' => $comparison['current_volume'],
            'previous_month_volume' => $comparison['previous_volume'],
            'difference' => $comparison['difference'],
            'percentage' => $comparison['percentage'],
        ];
    }

    /**
     * @return array{current_week_volume: float, previous_week_volume: float, difference: float, percentage: float}
     */
    public function getWeeklyVolumeComparison(User $user): array
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

        return [
            'current_week_volume' => $comparison['current_volume'],
            'previous_week_volume' => $comparison['previous_volume'],
            'difference' => $comparison['difference'],
            'percentage' => $comparison['percentage'],
        ];
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
                $data = DB::table('workouts')
                    ->where('user_id', $user->id)
                    ->where('started_at', '>=', now()->subMonths($months - 1)->startOfMonth())
                    ->select(['started_at', 'workout_volume as volume'])
                    ->get();

                $grouped = $data->groupBy(fn (object $row): string => Carbon::parse($row->started_at ?? (string) now())->format('Y-m'));

                return collect(range($months - 1, 0))
                    ->map(function (int $i) use ($grouped): array {
                        $date = now()->subMonths($i);
                        $month = $date->format('Y-m');
                        $monthData = $grouped->get($month);
                        $sum = $monthData ? $monthData->sum('volume') : 0.0;

                        return [
                            'month' => $date->translatedFormat('M'),
                            'volume' => (float) $sum,
                        ];
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
        $query = DB::table('workouts')->where('user_id', $user->id);

        if ($end) {
            $query->whereBetween('started_at', [$start, $end]);
        } else {
            $query->where('started_at', '>=', $start);
        }

        return (float) $query->sum('workout_volume');
    }
}
