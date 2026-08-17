<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\DTOs\Stats\BodyFatHistoryPoint;
use App\DTOs\Stats\LatestBodyMetrics;
use App\DTOs\Stats\WeightHistoryPoint;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class BodyStatsService
{
    public function getLatestBodyMetrics(User $user): LatestBodyMetrics
    {
        return Cache::remember(
            "stats.latest_metrics.{$user->id}",
            now()->addMinutes(30),
            function () use ($user): LatestBodyMetrics {
                $measurements = $user->bodyMeasurements()
                    // `id` et `user_id` etaient selectionnes sans jamais etre
                    // lus : la closure ne rend que le poids et la masse grasse.
                    // `measured_at` reste, il sert de cle de tri juste dessous.
                    ->select(['weight', 'body_fat', 'measured_at'])
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
                // ⚡ Bolt: PERFORMANCE OPTIMIZATION
                // Use toBase() to avoid hydrating Eloquent models and instantiating Carbon objects.
                // This significantly reduces memory usage and execution time for large datasets.
                $measurements = $user->bodyMeasurements()
                    ->toBase()
                    ->where('measured_at', '>=', now()->subDays($days))
                    ->orderBy('measured_at', 'asc')
                    ->get();

                $weightHistory = [];
                $bodyFatHistory = [];

                foreach ($measurements as $m) {
                    $timestamp = strtotime((string) $m->measured_at);

                    if ($timestamp === false) {
                        continue;
                    }

                    $date = date('d/m', $timestamp);
                    $fullDate = date('Y-m-d', $timestamp);

                    $weightHistory[] = new WeightHistoryPoint(
                        $date,
                        $fullDate,
                        (float) $m->weight,
                    );

                    if (isset($m->body_fat)) {
                        $bodyFatHistory[] = new BodyFatHistoryPoint(
                            $date,
                            $fullDate,
                            (float) $m->body_fat,
                        );
                    }
                }

                return [
                    'weightHistory' => $weightHistory,
                    'bodyFatHistory' => $bodyFatHistory,
                ];
            }
        );
    }
}
