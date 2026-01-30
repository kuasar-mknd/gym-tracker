<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkoutTemplate;

class WorkoutTemplatePolicy
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
    public function view(User $user, WorkoutTemplate $workoutTemplate): bool
    {
        return $user->id === $workoutTemplate->user_id;
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
    public function update(User $user, WorkoutTemplate $workoutTemplate): bool
    {
        return $user->id === $workoutTemplate->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WorkoutTemplate $workoutTemplate): bool
    {
        return $user->id === $workoutTemplate->user_id;
    }
}
