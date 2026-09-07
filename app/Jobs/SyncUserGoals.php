<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncUserGoals implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public \App\Models\User $user)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    public function handle(\App\Services\GoalService $goalService): void
    {
        $goalService->syncGoals($this->user);
    }
}
