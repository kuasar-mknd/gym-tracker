<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SupplementLog;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SupplementLogPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SupplementLog $supplementLog): bool
    {
        return $user->id === $supplementLog->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SupplementLog $supplementLog): bool
    {
        return $user->id === $supplementLog->user_id;
    }

    public function delete(User $user, SupplementLog $supplementLog): bool
    {
        return $user->id === $supplementLog->user_id;
    }
}
