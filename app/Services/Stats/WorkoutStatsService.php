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
            fn (): array => Workout::query()
                // ⚡ Bolt: PERFORMANCE OPTIMIZATION
                // Use toBase() to avoid hydrating Eloquent models and instantiating Carbon objects.
                // This significantly reduces memory usage and execution time for large datasets.
                ->toBase()
                ->select(['name', 'started_at', 'ended_at'])
                ->where('user_id', $user->id)
                ->whereNotNull('ended_at')
                ->latest('started_at')
                ->take($limit)
                ->get()
                ->map(function (object $workout): ?DurationHistoryPoint {
                    $start = strtotime((string) $workout->started_at);
                    $end = strtotime((string) $workout->ended_at);

                    if ($start === false || $end === false) {
                        return null;
                    }

                    return new DurationHistoryPoint(
                        date('d/m', $start),
                        (int) floor(abs($end - $start) / 60),
                        (string) ($workout->name ?? __('Workout')),
                    );
                })
                ->filter()
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
                    if (! is_string($workout->started_at) || strlen($workout->started_at) < 19) {
                        continue;
                    }

                    // Fast path: direct string parsing for the hour, bypassing strtotime()
                    $hour = (int) substr($workout->started_at, 11, 2);

                    $timeLabel = match (true) {
                        $hour >= 6 && $hour < 12 => 'Morning (06h-12h)',
                        $hour >= 12 && $hour < 17 => 'Afternoon (12h-17h)',
                        $hour >= 17 && $hour < 22 => 'Evening (17h-22h)',
                        default => 'Night (22h-06h)',
                    };
                    $timeBuckets[$timeLabel]++;

                    if (is_string($workout->ended_at) && strlen($workout->ended_at) >= 19) {
                        // Fast path: If the workout starts and ends on the same day, compute duration directly
                        // bypassing strtotime overhead.
                        if (substr($workout->started_at, 0, 10) === substr($workout->ended_at, 0, 10)) {
                            $h1 = (int) substr($workout->started_at, 11, 2);
                            $m1 = (int) substr($workout->started_at, 14, 2);
                            $h2 = (int) substr($workout->ended_at, 11, 2);
                            $m2 = (int) substr($workout->ended_at, 14, 2);
                            $minutes = abs(($h2 * 60 + $m2) - ($h1 * 60 + $m1));
                        } else {
                            // Fallback to strtotime for workouts spanning multiple days
                            $startedAtTimestamp = strtotime($workout->started_at);
                            $endedAtTimestamp = strtotime($workout->ended_at);
                            if ($startedAtTimestamp !== false && $endedAtTimestamp !== false) {
                                $minutes = (int) floor(abs($endedAtTimestamp - $startedAtTimestamp) / 60);
                            } else {
                                continue;
                            }
                        }

                        $durationLabel = match (true) {
                            $minutes < 30 => '< 30 min',
                            $minutes < 60 => '30-60 min',
                            $minutes < 90 => '60-90 min',
                            default => '90+ min',
                        };
                        $durationBuckets[$durationLabel]++;
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
