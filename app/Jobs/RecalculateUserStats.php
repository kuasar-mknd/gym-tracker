<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\StatsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RecalculateUserStats implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public User $user)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(StatsService $statsService): void
    {
        // ⚡ Bolt: Use surgical cache invalidation instead of broad clearUserStatsCache
        // to prevent unnecessary invalidation of body measurement statistics when workout data changes.
        $statsService->clearWorkoutRelatedStats($this->user);
        $statsService->clearWorkoutMetadataStats($this->user);

        // Warm up critical stats
        $statsService->getVolumeTrend($this->user, 30);
        $statsService->getMuscleDistribution($this->user, 30);
    }
}
