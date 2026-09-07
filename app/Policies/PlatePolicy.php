<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Plate;
use App\Models\User;

final class PlatePolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(User $user, Plate $plate): bool
    {
        return $user->id === $plate->user_id;
    }

    public function create(): bool
    {
        return true;
    }

    public function update(User $user, Plate $plate): bool
    {
        return $user->id === $plate->user_id;
    }

    public function delete(User $user, Plate $plate): bool
    {
        return $user->id === $plate->user_id;
    }
}
