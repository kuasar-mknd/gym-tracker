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
        // Invalidation ciblée : on ne touche pas au cache des mesures corporelles,
        // qu'un changement de données de séance ne périme pas.
        $statsService->clearWorkoutRelatedStats($this->user);
        $statsService->clearWorkoutMetadataStats($this->user);

        // Warm up critical stats
        $statsService->getVolumeTrend($this->user, 30);
        $statsService->getMuscleDistribution($this->user, 30);
    }
}
