<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\AchievementService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncUserAchievements implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public User $user)
    {
    }

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    /**
     * Execute the job.
     */
    public function handle(AchievementService $achievementService): void
    {
        $achievementService->syncAchievements($this->user);
    }
}
