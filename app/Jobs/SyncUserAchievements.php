<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncUserAchievements implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public \App\Models\User $user) {}

    /**
     * Execute the job.
     */
    public function handle(\App\Services\AchievementService $achievementService): void
    {
        $achievementService->syncAchievements($this->user);
    }
}
