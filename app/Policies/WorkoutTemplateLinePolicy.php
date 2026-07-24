<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;

final class WorkoutTemplateLinePolicy
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
    public function view(User $user, WorkoutTemplateLine $workoutTemplateLine): bool
    {
        return $user->id === $workoutTemplateLine->workoutTemplate->user_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, ?WorkoutTemplate $workoutTemplate = null): bool
    {
        if ($workoutTemplate === null) {
            return true;
        }

        return $user->id === $workoutTemplate->user_id;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, WorkoutTemplateLine $workoutTemplateLine): bool
    {
        return $user->id === $workoutTemplateLine->workoutTemplate->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WorkoutTemplateLine $workoutTemplateLine): bool
    {
        return $user->id === $workoutTemplateLine->workoutTemplate->user_id;
    }
}
