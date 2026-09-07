<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Habit;
use App\Models\User;

final class HabitPolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(User $user, Habit $habit): bool
    {
        return $user->id === $habit->user_id;
    }

    public function create(): bool
    {
        return true;
    }

    public function update(User $user, Habit $habit): bool
    {
        return $user->id === $habit->user_id;
    }

    public function delete(User $user, Habit $habit): bool
    {
        return $user->id === $habit->user_id;
    }
}
