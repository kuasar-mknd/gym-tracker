<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WaterLog;

final class WaterLogPolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(User $user, WaterLog $waterLog): bool
    {
        return $user->id === $waterLog->user_id;
    }

    public function create(): bool
    {
        return true;
    }

    public function update(User $user, WaterLog $waterLog): bool
    {
        return $user->id === $waterLog->user_id;
    }

    public function delete(User $user, WaterLog $waterLog): bool
    {
        return $user->id === $waterLog->user_id;
    }
}
