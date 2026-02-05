<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Fast;
use App\Models\User;

class FastPolicy
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
    public function view(User $user, Fast $fast): bool
    {
        return $user->id === $fast->user_id;
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
    public function update(User $user, Fast $fast): bool
    {
        return $user->id === $fast->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Fast $fast): bool
    {
        return $user->id === $fast->user_id;
    }
}
