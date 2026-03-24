<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\DTOs\Stats\BodyFatHistoryPoint;
use App\DTOs\Stats\LatestBodyMetrics;
use App\DTOs\Stats\WeightHistoryPoint;
use App\Models\BodyMeasurement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

final class BodyStatsService
{
    /**
     * @return array<int, WeightHistoryPoint>
     */
    public function getWeightHistory(User $user, int $days = 90): array
    {
        return Cache::remember(
            "stats.weight_history.{$user->id}.{$days}",
            now()->addMinutes(30),
            fn (): array => $user->bodyMeasurements()
                ->where('measured_at', '>=', now()->subDays($days))
                ->orderBy('measured_at', 'asc')
                ->get()
                ->map(fn (BodyMeasurement $m): WeightHistoryPoint => new WeightHistoryPoint(
                    Carbon::parse($m->measured_at)->format('d/m'),
                    Carbon::parse($m->measured_at)->format('Y-m-d'),
                    (float) $m->weight,
                ))
                ->toArray()
        );
    }

    public function getLatestBodyMetrics(User $user): LatestBodyMetrics
    {
        return Cache::remember(
            "stats.latest_metrics.{$user->id}",
            now()->addMinutes(30),
            function () use ($user): LatestBodyMetrics {
                $measurements = $user->bodyMeasurements()
                    ->select(['id', 'user_id', 'weight', 'body_fat', 'measured_at'])
                    ->latest('measured_at')
                    ->take(2)
                    ->get();

                $latest = $measurements->first();
                $previous = $measurements->skip(1)->first();

                $weightChange = $latest && $previous ? round($latest->weight - $previous->weight, 1) : 0;

                return new LatestBodyMetrics(
                    $latest?->weight,
                    (float) $weightChange,
                    $latest?->body_fat,
                );
            }
        );
    }

    /**
     * @return array<int, BodyFatHistoryPoint>
     */
    public function getBodyFatHistory(User $user, int $days = 90): array
    {
        return Cache::remember(
            "stats.body_fat_history.{$user->id}.{$days}",
            now()->addMinutes(30),
            fn (): array => $user->bodyMeasurements()
                ->where('measured_at', '>=', now()->subDays($days))
                ->whereNotNull('body_fat')
                ->orderBy('measured_at', 'asc')
                ->get()
                ->map(fn (BodyMeasurement $m): BodyFatHistoryPoint => new BodyFatHistoryPoint(
                    Carbon::parse($m->measured_at)->format('d/m'),
                    Carbon::parse($m->measured_at)->format('Y-m-d'),
                    (float) $m->body_fat,
                ))
                ->toArray()
        );
    }

    /**
     * Get consolidated body progress data (weight and body fat history).
     * ⚡ Bolt: Reduces 2 database queries to 1 and uses a single cache key.
     *
     * @param  User  $user  The user to fetch stats for.
     * @param  int  $days  The number of days to look back.
     * @return array{weightHistory: array<int, WeightHistoryPoint>, bodyFatHistory: array<int, BodyFatHistoryPoint>}
     */
    public function getBodyProgressOverview(User $user, int $days = 90): array
    {
        return Cache::remember(
            "stats.body_progress.{$user->id}.{$days}",
            now()->addMinutes(30),
            function () use ($user, $days): array {
                $measurements = $user->bodyMeasurements()
                    ->where('measured_at', '>=', now()->subDays($days))
                    ->orderBy('measured_at', 'asc')
                    ->get();

                $weightHistory = $measurements->map(fn (BodyMeasurement $m): WeightHistoryPoint => new WeightHistoryPoint(
                    Carbon::parse($m->measured_at)->format('d/m'),
                    Carbon::parse($m->measured_at)->format('Y-m-d'),
                    (float) $m->weight,
                ))->toArray();

                $bodyFatHistory = $measurements->filter(fn (BodyMeasurement $m) => $m->body_fat !== null)
                    ->map(fn (BodyMeasurement $m): BodyFatHistoryPoint => new BodyFatHistoryPoint(
                        Carbon::parse($m->measured_at)->format('d/m'),
                        Carbon::parse($m->measured_at)->format('Y-m-d'),
                        (float) $m->body_fat,
                    ))->values()->toArray();

                return [
                    'weightHistory' => $weightHistory,
                    'bodyFatHistory' => $bodyFatHistory,
                ];
            }
        );
    }
}
