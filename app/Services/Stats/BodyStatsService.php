<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\Models\BodyMeasurement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

final class BodyStatsService
{
    /**
     * @return array<int, array{date: string, full_date: string, weight: float}>
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
                ->map(fn (BodyMeasurement $m): array => [
                    'date' => Carbon::parse($m->measured_at)->format('d/m'),
                    'full_date' => Carbon::parse($m->measured_at)->format('Y-m-d'),
                    'weight' => (float) $m->weight,
                ])
                ->toArray()
        );
    }

    /**
     * @return array{latest_weight: float|string|null, weight_change: float, latest_body_fat: float|string|null}
     */
    public function getLatestBodyMetrics(User $user): array
    {
        return Cache::remember(
            "stats.latest_metrics.{$user->id}",
            now()->addMinutes(30),
            function () use ($user): array {
                $measurements = $user->bodyMeasurements()
                    ->select(['id', 'user_id', 'weight', 'body_fat', 'measured_at'])
                    ->latest('measured_at')
                    ->take(2)
                    ->get();

                $latest = $measurements->first();
                $previous = $measurements->skip(1)->first();

                $weightChange = $latest && $previous ? round($latest->weight - $previous->weight, 1) : 0;

                return [
                    'latest_weight' => $latest?->weight,
                    'weight_change' => (float) $weightChange,
                    'latest_body_fat' => $latest?->body_fat,
                ];
            }
        );
    }

    /**
     * @return array<int, array{date: string, full_date: string, body_fat: float}>
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
                ->map(fn (BodyMeasurement $m): array => [
                    'date' => Carbon::parse($m->measured_at)->format('d/m'),
                    'full_date' => Carbon::parse($m->measured_at)->format('Y-m-d'),
                    'body_fat' => (float) $m->body_fat,
                ])
                ->toArray()
        );
    }
}
