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
     * Get consolidated workout distributions (duration + time of day).
     * ⚡ Bolt: Reduces 2 database queries to 1 and uses a single cache key.
     *
     * @param  User  $user  The user to fetch stats for.
     * @param  int  $days  The number of days to look back.
     * @return array{duration: array<int, DistributionStat>, time_of_day: array<int, DistributionStat>}
     */
    public function getWorkoutDistributions(User $user, int $days = 90): array
    {
        return Cache::remember(
            "stats.workout_distributions.{$user->id}.{$days}",
            now()->addMinutes(30),
            function () use ($user, $days): array {
                // ⚡ Bolt: PERFORMANCE OPTIMIZATION
                // Use toBase() to avoid hydrating Eloquent models and instantiating Carbon objects.
                // This significantly reduces memory usage and execution time for large datasets.
                $workouts = $user->workouts()
                    ->toBase()
                    ->select(['id', 'started_at', 'ended_at'])
                    ->where('started_at', '>=', now()->subDays($days))
                    ->get();

                $durationBuckets = [
                    '< 30 min' => 0,
                    '30-60 min' => 0,
                    '60-90 min' => 0,
                    '90+ min' => 0,
                ];

                $timeBuckets = [
                    'Morning (06h-12h)' => 0,
                    'Afternoon (12h-17h)' => 0,
                    'Evening (17h-22h)' => 0,
                    'Night (22h-06h)' => 0,
                ];

                foreach ($workouts as $workout) {
                    // Time of day calculation using native PHP string/date parsing
                    /** @var int|false $startedAtTimestamp */
                    $startedAtTimestamp = is_string($workout->started_at) ? strtotime($workout->started_at) : false;

                    if ($startedAtTimestamp === false) {
                        continue;
                    }

                    $hour = (int) date('G', $startedAtTimestamp);
                    $timeLabel = match (true) {
                        $hour >= 6 && $hour < 12 => 'Morning (06h-12h)',
                        $hour >= 12 && $hour < 17 => 'Afternoon (12h-17h)',
                        $hour >= 17 && $hour < 22 => 'Evening (17h-22h)',
                        default => 'Night (22h-06h)',
                    };
                    $timeBuckets[$timeLabel]++;

                    // Duration calculation using native timestamp differences
                    if ($workout->ended_at) {
                        /** @var int|false $endedAtTimestamp */
                        $endedAtTimestamp = is_string($workout->ended_at) ? strtotime($workout->ended_at) : false;

                        if ($endedAtTimestamp !== false) {
                            $minutes = (int) floor(abs($endedAtTimestamp - $startedAtTimestamp) / 60);
                            $durationLabel = match (true) {
                                $minutes < 30 => '< 30 min',
                                $minutes < 60 => '30-60 min',
                                $minutes < 90 => '60-90 min',
                                default => '90+ min',
                            };
                            $durationBuckets[$durationLabel]++;
                        }
                    }
                }

                return [
                    'duration' => collect($durationBuckets)
                        ->map(fn (int $count, string $label): DistributionStat => new DistributionStat(__($label), $count))
                        ->values()
                        ->all(),
                    'time_of_day' => collect($timeBuckets)
                        ->map(fn (int $count, string $label): DistributionStat => new DistributionStat(__($label), $count))
                        ->values()
                        ->all(),
                ];
            }
        );
    }
}
