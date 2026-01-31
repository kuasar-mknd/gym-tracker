<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\HabitLog;
use App\Models\User;

class HabitLogPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, HabitLog $habitLog): bool
    {
        return $user->id === $habitLog->habit->user_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, HabitLog $habitLog): bool
    {
        return $user->id === $habitLog->habit->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, HabitLog $habitLog): bool
    {
        return $user->id === $habitLog->habit->user_id;
    }
}
