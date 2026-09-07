<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncUserAchievements implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public \App\Models\User $user)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    public function handle(\App\Services\AchievementService $achievementService): void
    {
        $achievementService->syncAchievements($this->user);
    }
}
