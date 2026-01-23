<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BodyMeasurement;
use App\Models\User;

final class BodyMeasurementPolicy
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
    public function view(User $user, BodyMeasurement $bodyMeasurement): bool
    {
        return $user->id === $bodyMeasurement->user_id;
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
    public function update(User $user, BodyMeasurement $bodyMeasurement): bool
    {
        return $user->id === $bodyMeasurement->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, BodyMeasurement $bodyMeasurement): bool
    {
        return $user->id === $bodyMeasurement->user_id;
    }
}
