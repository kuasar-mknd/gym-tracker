<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RecalculateUserStats implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public \App\Models\User $user)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(\App\Services\StatsService $statsService): void
    {
        // ⚡ Bolt: Use surgical cache invalidation instead of tags() as database driver doesn't support tags.
        $statsService->clearUserStatsCache($this->user);

        // Warm up critical stats
        $statsService->getVolumeTrend($this->user, 30);
        $statsService->getMuscleDistribution($this->user, 30);
    }
}
