<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Set;
use App\Models\User;

final class SetPolicy
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
    public function view(User $user, Set $set): bool
    {
        return $set->workoutLine?->workout?->user_id === $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, ?\App\Models\WorkoutLine $workoutLine = null): bool
    {
        if ($workoutLine === null) {
            return true;
        }

        $workout = $workoutLine->workout;

        if ($workout === null) {
            return false;
        }

        return $user->id === $workout->user_id && is_null($workout->ended_at);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Set $set): bool
    {
        $workout = $set->workoutLine?->workout;

        if ($workout === null) {
            return false;
        }

        return $user->id === $workout->user_id && is_null($workout->ended_at);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Set $set): bool
    {
        $workout = $set->workoutLine?->workout;

        if ($workout === null) {
            return false;
        }

        return $user->id === $workout->user_id && is_null($workout->ended_at);
    }
}
