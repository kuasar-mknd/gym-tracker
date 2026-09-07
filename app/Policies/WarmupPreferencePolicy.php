<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WarmupPreference;

class WarmupPreferencePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WarmupPreference $warmupPreference): bool
    {
        return $user->id === $warmupPreference->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, WarmupPreference $warmupPreference): bool
    {
        return $user->id === $warmupPreference->user_id;
    }

    public function delete(User $user, WarmupPreference $warmupPreference): bool
    {
        return $user->id === $warmupPreference->user_id;
    }

    public function restore(User $user, WarmupPreference $warmupPreference): bool
    {
        return $user->id === $warmupPreference->user_id;
    }

    public function forceDelete(User $user, WarmupPreference $warmupPreference): bool
    {
        return $user->id === $warmupPreference->user_id;
    }
}
