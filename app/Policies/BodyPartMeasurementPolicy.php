<?php

namespace App\Policies;

use App\Models\BodyPartMeasurement;
use App\Models\User;

class BodyPartMeasurementPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, BodyPartMeasurement $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, BodyPartMeasurement $model): bool
    {
        return $user->id === $model->user_id;
    }

    public function delete(User $user, BodyPartMeasurement $model): bool
    {
        return $user->id === $model->user_id;
    }
}
