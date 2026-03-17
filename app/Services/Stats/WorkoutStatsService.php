<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\DTOs\Stats\DistributionStat;
use App\DTOs\Stats\DurationHistoryPoint;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Facades\Cache;

final class WorkoutStatsService
{
    /**
     * @return array<int, DurationHistoryPoint>
     */
    public function getDurationHistory(User $user, int $limit = 20): array
    {
        return Cache::remember(
            "stats.duration_history.{$user->id}.{$limit}",
            now()->addMinutes(30),
            fn (): array => Workout::select(['name', 'started_at', 'ended_at'])
                ->where('user_id', $user->id)
                ->whereNotNull('ended_at')
                ->latest('started_at')
                ->take($limit)
                ->get()
                ->map(fn (Workout $workout): DurationHistoryPoint => new DurationHistoryPoint(
                    $workout->started_at->format('d/m'),
                    $workout->ended_at ? (int) abs($workout->started_at->diffInMinutes($workout->ended_at)) : 0,
                    $workout->name ?? __('Workout'),
                ))
                ->reverse()->values()->toArray()
        );
    }

    /**
     * @return array<int, DistributionStat>
     */
    public function getDurationDistribution(User $user, int $days = 90): array
    {
        return Cache::remember(
            "stats.duration_distribution.{$user->id}.{$days}",
            now()->addMinutes(30),
            function () use ($user, $days): array {
                $workouts = $user->workouts()
                    ->select(['id', 'user_id', 'started_at', 'ended_at'])
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
                    $minutes = (int) abs($workout->started_at->diffInMinutes($workout->ended_at));
                    $label = match (true) {
                        $minutes < 30 => '< 30 min',
                        $minutes < 60 => '30-60 min',
                        $minutes < 90 => '60-90 min',
                        default => '90+ min',
                    };
                    $buckets[$label]++;
                }

                return collect($buckets)
                    ->map(fn (int $count, string $label): DistributionStat => new DistributionStat(__($label), $count))
                    ->values()
                    ->all();
            }
        );
    }

    /**
     * @return array<int, DistributionStat>
     */
    public function getTimeOfDayDistribution(User $user, int $days = 90): array
    {
        return Cache::remember(
            "stats.time_of_day_distribution.{$user->id}.{$days}",
            now()->addMinutes(30),
            function () use ($user, $days): array {
                $workouts = $user->workouts()
                    ->select(['id', 'user_id', 'started_at'])
                    ->where('started_at', '>=', now()->subDays($days))
                    ->get();

                $buckets = [
                    'Morning (06h-12h)' => 0,
                    'Afternoon (12h-17h)' => 0,
                    'Evening (17h-22h)' => 0,
                    'Night (22h-06h)' => 0,
                ];

                foreach ($workouts as $workout) {
                    $hour = (int) $workout->started_at->format('G');
                    $label = match (true) {
                        $hour >= 6 && $hour < 12 => 'Morning (06h-12h)',
                        $hour >= 12 && $hour < 17 => 'Afternoon (12h-17h)',
                        $hour >= 17 && $hour < 22 => 'Evening (17h-22h)',
                        default => 'Night (22h-06h)',
                    };
                    $buckets[$label]++;
                }

                return collect($buckets)
                    ->map(fn (int $count, string $label): DistributionStat => new DistributionStat(__($label), $count))
                    ->values()
                    ->all();
            }
        );
    }
}
