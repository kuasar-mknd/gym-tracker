<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkoutLine;

class WorkoutLinePolicy
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
    public function view(User $user, WorkoutLine $workoutLine): bool
    {
        return $user->id === $workoutLine->workout->user_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, ?\App\Models\Workout $workout = null): bool
    {
        if ($workout === null) {
            return true;
        }

        return $user->id === $workout->user_id;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, WorkoutLine $workoutLine): bool
    {
        return $user->id === $workoutLine->workout->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WorkoutLine $workoutLine): bool
    {
        return $user->id === $workoutLine->workout->user_id;
    }
}
