<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WarmupPreference;

class WarmupPreferencePolicy
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
    public function view(User $user, WarmupPreference $warmupPreference): bool
    {
        return $user->id === $warmupPreference->user_id;
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
    public function update(User $user, WarmupPreference $warmupPreference): bool
    {
        return $user->id === $warmupPreference->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WarmupPreference $warmupPreference): bool
    {
        return $user->id === $warmupPreference->user_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, WarmupPreference $warmupPreference): bool
    {
        return $user->id === $warmupPreference->user_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, WarmupPreference $warmupPreference): bool
    {
        return $user->id === $warmupPreference->user_id;
    }
}
