<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BodyMeasurement;
use App\Models\User;

final class BodyMeasurementPolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(User $user, BodyMeasurement $bodyMeasurement): bool
    {
        return $user->id === $bodyMeasurement->user_id;
    }

    public function create(): bool
    {
        return true;
    }

    public function update(User $user, BodyMeasurement $bodyMeasurement): bool
    {
        return $user->id === $bodyMeasurement->user_id;
    }

    public function delete(User $user, BodyMeasurement $bodyMeasurement): bool
    {
        return $user->id === $bodyMeasurement->user_id;
    }
}
