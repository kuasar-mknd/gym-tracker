<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkoutTemplateSet;
use App\Models\WorkoutTemplateLine;

final class WorkoutTemplateSetPolicy
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
    public function view(User $user, WorkoutTemplateSet $workoutTemplateSet): bool
    {
        return $user->id === $workoutTemplateSet->workoutTemplateLine->workoutTemplate->user_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, ?WorkoutTemplateLine $workoutTemplateLine = null): bool
    {
        if ($workoutTemplateLine === null) {
            return true;
        }

        return $user->id === $workoutTemplateLine->workoutTemplate->user_id;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, WorkoutTemplateSet $workoutTemplateSet): bool
    {
        return $user->id === $workoutTemplateSet->workoutTemplateLine->workoutTemplate->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WorkoutTemplateSet $workoutTemplateSet): bool
    {
        return $user->id === $workoutTemplateSet->workoutTemplateLine->workoutTemplate->user_id;
    }
}
