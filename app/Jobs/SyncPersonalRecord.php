<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Set;
use App\Models\User;
use App\Services\PersonalRecordService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncPersonalRecord implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public Set $set,
        public User $user
    ) {
    }

    public function uniqueId(): string
    {
        return "sync_pr_{$this->user->id}_{$this->set->id}";
    }

    public function handle(PersonalRecordService $personalRecordService): void
    {
        $personalRecordService->syncSetPRs($this->set, $this->user);
    }
}
