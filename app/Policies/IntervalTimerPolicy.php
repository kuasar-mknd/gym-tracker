<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\IntervalTimer;
use App\Models\User;

class IntervalTimerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, IntervalTimer $intervalTimer): bool
    {
        return $user->id === $intervalTimer->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, IntervalTimer $intervalTimer): bool
    {
        return $user->id === $intervalTimer->user_id;
    }

    public function delete(User $user, IntervalTimer $intervalTimer): bool
    {
        return $user->id === $intervalTimer->user_id;
    }
}
