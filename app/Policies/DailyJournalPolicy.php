<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DailyJournal;
use App\Models\User;

final class DailyJournalPolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(User $user, DailyJournal $dailyJournal): bool
    {
        return $user->id === $dailyJournal->user_id;
    }

    public function create(): bool
    {
        return true;
    }

    public function update(User $user, DailyJournal $dailyJournal): bool
    {
        return $user->id === $dailyJournal->user_id;
    }

    public function delete(User $user, DailyJournal $dailyJournal): bool
    {
        return $user->id === $dailyJournal->user_id;
    }
}
