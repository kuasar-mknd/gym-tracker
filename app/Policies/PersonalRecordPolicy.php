<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PersonalRecord;
use App\Models\User;

final class PersonalRecordPolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(User $user, PersonalRecord $personalRecord): bool
    {
        return $user->id === $personalRecord->user_id;
    }

    public function create(): bool
    {
        return true;
    }

    public function update(User $user, PersonalRecord $personalRecord): bool
    {
        return $user->id === $personalRecord->user_id;
    }

    public function delete(User $user, PersonalRecord $personalRecord): bool
    {
        return $user->id === $personalRecord->user_id;
    }
}
