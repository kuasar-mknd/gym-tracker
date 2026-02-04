<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\IntervalTimer;
use App\Models\User;

final class IntervalTimerPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, IntervalTimer $intervalTimer): bool
    {
        return (int) $user->id === (int) $intervalTimer->user_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, IntervalTimer $intervalTimer): bool
    {
        return (int) $user->id === (int) $intervalTimer->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, IntervalTimer $intervalTimer): bool
    {
        return (int) $user->id === (int) $intervalTimer->user_id;
    }
}
