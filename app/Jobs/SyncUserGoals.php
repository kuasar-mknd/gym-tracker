<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\GoalService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncUserGoals implements ShouldBeUnique, ShouldQueue
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
    public function handle(GoalService $goalService): void
    {
        $goalService->syncGoals($this->user);
    }
}
