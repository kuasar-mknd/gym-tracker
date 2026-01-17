<?php

namespace App\Policies;

use App\Models\Set;
use App\Models\User;

class SetPolicy
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
    public function view(User $user, Set $set): bool
    {
        return $user->id === $set->workoutLine->workout->user_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, ?\App\Models\WorkoutLine $workoutLine = null): bool
    {
        if ($workoutLine === null) {
            return true;
        }

        return $user->id === $workoutLine->workout->user_id && is_null($workoutLine->workout->ended_at);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Set $set): bool
    {
        return $user->id === $set->workoutLine->workout->user_id && is_null($set->workoutLine->workout->ended_at);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Set $set): bool
    {
        return $user->id === $set->workoutLine->workout->user_id && is_null($set->workoutLine->workout->ended_at);
    }
}
