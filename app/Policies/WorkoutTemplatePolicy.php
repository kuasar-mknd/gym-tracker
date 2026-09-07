<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkoutTemplate;

final class WorkoutTemplatePolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(User $user, WorkoutTemplate $workoutTemplate): bool
    {
        return $user->id === $workoutTemplate->user_id;
    }

    public function create(): bool
    {
        return true;
    }

    public function update(User $user, WorkoutTemplate $workoutTemplate): bool
    {
        return $user->id === $workoutTemplate->user_id;
    }

    public function delete(User $user, WorkoutTemplate $workoutTemplate): bool
    {
        return $user->id === $workoutTemplate->user_id;
    }
}
