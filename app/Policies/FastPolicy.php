<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Fast;
use App\Models\User;

class FastPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Fast $fast): bool
    {
        return $user->id === $fast->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Fast $fast): bool
    {
        return $user->id === $fast->user_id;
    }

    public function delete(User $user, Fast $fast): bool
    {
        return $user->id === $fast->user_id;
    }
}
