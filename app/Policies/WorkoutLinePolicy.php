<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkoutLine;

final class WorkoutLinePolicy
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
    public function view(User $user, WorkoutLine $workoutLine): bool
    {
        $workout = $workoutLine->workout;

        if ($workout === null) {
            return false;
        }

        return $user->id === $workout->user_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, ?\App\Models\Workout $workout = null): bool
    {
        if ($workout === null) {
            return true;
        }

        return $user->id === $workout->user_id && is_null($workout->ended_at);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, WorkoutLine $workoutLine): bool
    {
        $workout = $workoutLine->workout;

        if ($workout === null) {
            return false;
        }

        return $user->id === $workout->user_id && is_null($workout->ended_at);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WorkoutLine $workoutLine): bool
    {
        $workout = $workoutLine->workout;

        if ($workout === null) {
            return false;
        }

        return $user->id === $workout->user_id && is_null($workout->ended_at);
    }
}
