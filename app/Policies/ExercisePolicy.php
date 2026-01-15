<?php

namespace App\Policies;

use App\Models\Exercise;
use App\Models\User;

class ExercisePolicy
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
    public function view(User $user, Exercise $exercise): bool
    {
        return $exercise->user_id === null || $user->id === $exercise->user_id;
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
    public function update(User $user, Exercise $exercise): bool
    {
        return $exercise->user_id !== null && $user->id === $exercise->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Exercise $exercise): bool
    {
        return $exercise->user_id !== null && $user->id === $exercise->user_id;
    }
}
