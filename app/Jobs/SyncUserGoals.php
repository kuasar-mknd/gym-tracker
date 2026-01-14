<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncUserGoals implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public \App\Models\User $user)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(\App\Services\GoalService $goalService): void
    {
        $goalService->syncGoals($this->user);
    }
}
